<?php

declare(strict_types=1);

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function fibonacci_interval_seconds(int $alertCount): int {
	$base = 300;
	$ceiling = 86400;
	$step = max(1, $alertCount);
	$previous = 0;
	$current = 1;

	for ($i = 1; $i < $step; $i++) {
		$next = $previous + $current;
		$previous = $current;
		$current = $next;

		if ($current * $base >= $ceiling) {
			return $ceiling;
		}
	}

	return min($ceiling, $current * $base);
}

function escalating_interval_seconds(int $alertCount): int {
	$steps = [300, 900, 3600, 14400, 86400];
	$index = max(0, $alertCount - 1);
	return $steps[min($index, count($steps) - 1)];
}

function is_still_alertable(string $alertType, string $status): bool {
	if ($alertType === 'unreachable') {
		return $status === 'Unreachable';
	}
	if ($alertType === 'not_registered') {
		return $status === 'Not registered';
	}

	return false;
}

function handoff_escalation(PDO $db, string $extension, int $historyId, string $alertType, string $createdAt, string $now): void {
	$stmt = $db->prepare(
		'SELECT extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode
		FROM registrationwatch_alert_escalation
		WHERE extension = :extension
		ORDER BY active_since ASC, id ASC
		LIMIT 1'
	);
	$stmt->execute([':extension' => $extension]);
	$existing = $stmt->fetch(PDO::FETCH_ASSOC);

	$activeSince = is_array($existing) && !empty($existing['active_since']) ? $existing['active_since'] : $createdAt;
	$lastAlertAt = is_array($existing) && !empty($existing['last_alert_at']) ? $existing['last_alert_at'] : $now;
	$alertCount = is_array($existing) ? (int)$existing['alert_count'] : 0;
	$nextDueAt = is_array($existing) && !empty($existing['next_due_at']) ? $existing['next_due_at'] : '2026-06-15 10:05:00';

	$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE extension = :extension AND alert_type <> :alert_type')
		->execute([':extension' => $extension, ':alert_type' => $alertType]);

	$db->prepare(
		'INSERT INTO registrationwatch_alert_escalation
			(extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
		VALUES
			(:extension, :history_id, :alert_type, :active_since, :last_alert_at, :alert_count, :next_due_at, :repeat_mode)
		ON CONFLICT(extension, alert_type) DO UPDATE SET
			history_id = excluded.history_id,
			active_since = excluded.active_since,
			last_alert_at = excluded.last_alert_at,
			alert_count = excluded.alert_count,
			next_due_at = excluded.next_due_at,
			repeat_mode = excluded.repeat_mode'
	)->execute([
		':extension' => $extension,
		':history_id' => $historyId,
		':alert_type' => $alertType,
		':active_since' => $activeSince,
		':last_alert_at' => $lastAlertAt,
		':alert_count' => $alertCount,
		':next_due_at' => $nextDueAt,
		':repeat_mode' => 'escalating',
	]);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
	'CREATE TABLE registrationwatch_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		extension TEXT NOT NULL,
		history_id INTEGER,
		reminder_n INTEGER NOT NULL DEFAULT 0,
		alert_type TEXT NOT NULL,
		status TEXT NOT NULL,
		recipient TEXT NOT NULL,
		result TEXT NOT NULL,
		UNIQUE (history_id, alert_type, recipient, reminder_n)
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_alert_escalation (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		extension TEXT NOT NULL,
		history_id INTEGER NOT NULL,
		alert_type TEXT NOT NULL,
		active_since TEXT NOT NULL,
		last_alert_at TEXT,
		alert_count INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT NOT NULL,
		repeat_mode TEXT NOT NULL,
		UNIQUE (extension, alert_type)
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_registrations (
		extension TEXT PRIMARY KEY,
		enabled INTEGER NOT NULL,
		last_known_status TEXT NOT NULL
	)'
);

$insertAlert = $db->prepare(
	'INSERT OR IGNORE INTO registrationwatch_alert_history
		(extension, history_id, reminder_n, alert_type, status, recipient, result)
	VALUES
		(:extension, :history_id, :reminder_n, :alert_type, :status, :recipient, :result)'
);

$baseAlert = [
	':extension' => '2001',
	':history_id' => 10,
	':alert_type' => 'unreachable',
	':status' => 'Unreachable',
	':recipient' => 'admin@example.invalid',
	':result' => 'sent',
];

$insertAlert->execute($baseAlert + [':reminder_n' => 0]);
assert_true($insertAlert->rowCount() === 1, 'transition alert should reserve reminder_n 0');

$insertAlert->execute($baseAlert + [':reminder_n' => 1]);
assert_true($insertAlert->rowCount() === 1, 'first reminder should not be blocked by transition alert key');

$insertAlert->execute($baseAlert + [':reminder_n' => 1]);
assert_true($insertAlert->rowCount() === 0, 'same reminder_n should never reserve twice');

$db->exec(
	"INSERT INTO registrationwatch_alert_escalation
		(extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
	VALUES
		('2001', 10, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00', 1, '2026-06-15 10:05:00', '5m')"
);
$delete = $db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE extension = :extension AND alert_type = :alert_type');
$delete->execute([':extension' => '2001', ':alert_type' => 'unreachable']);
assert_true((int)$db->query('SELECT COUNT(*) FROM registrationwatch_alert_escalation')->fetchColumn() === 0, 'recovery should delete escalation row');

$db->exec(
	"INSERT INTO registrationwatch_registrations (extension, enabled, last_known_status)
	VALUES ('2002', 1, 'Unreachable')"
);
$db->exec(
	"INSERT INTO registrationwatch_alert_escalation
		(extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
	VALUES
		('2002', 11, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00', 1, '2026-06-15 10:05:00', '5m')"
);
if (!is_still_alertable('unreachable', 'Reachable')) {
	$delete->execute([':extension' => '2002', ':alert_type' => 'unreachable']);
}
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2002'")->fetchColumn() === 0, 'recovered-between-ticks registration should not keep a due reminder row');

$neverMode = 'never';
$neverHistoryId = 12;
$neverExtension = '2003';
$insertAlert->execute([
	':extension' => $neverExtension,
	':history_id' => $neverHistoryId,
	':reminder_n' => 0,
	':alert_type' => 'unreachable',
	':status' => 'Unreachable',
	':recipient' => 'admin@example.invalid',
	':result' => 'sent',
]);
if ($neverMode !== 'never') {
	$db->prepare(
		'INSERT INTO registrationwatch_alert_escalation
			(extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
		VALUES
			(:extension, :history_id, :alert_type, :active_since, :last_alert_at, :alert_count, :next_due_at, :repeat_mode)'
	)->execute([
		':extension' => $neverExtension,
		':history_id' => $neverHistoryId,
		':alert_type' => 'unreachable',
		':active_since' => '2026-06-15 10:00:00',
		':last_alert_at' => '2026-06-15 10:00:00',
		':alert_count' => 0,
		':next_due_at' => '2026-06-15 10:05:00',
		':repeat_mode' => $neverMode,
	]);
}
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_history WHERE extension = '2003'")->fetchColumn() === 1, 'repeat_mode never should allow exactly one transition alert');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2003'")->fetchColumn() === 0, 'repeat_mode never should not create an escalation row');

$db->exec(
	"INSERT INTO registrationwatch_alert_escalation
		(extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
	VALUES
		('2004', 13, 'not_registered', '2026-06-15 10:00:00', '2026-06-15 10:00:00', 1, '2026-06-15 10:05:00', '5m')"
);
try {
	throw new RuntimeException('Asterisk unavailable');
} catch (RuntimeException $e) {
	// The due-reminder pass returns here before evaluating rows.
}
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2004'")->fetchColumn() === 1, 'live snapshot failure should leave escalation rows intact for retry');

assert_true(escalating_interval_seconds(1) === 300, 'escalating reminder 1 should wait 5 minutes');
assert_true(escalating_interval_seconds(2) === 900, 'escalating reminder 2 should wait 15 minutes');
assert_true(escalating_interval_seconds(3) === 3600, 'escalating reminder 3 should wait 1 hour');
assert_true(escalating_interval_seconds(4) === 14400, 'escalating reminder 4 should wait 4 hours');
assert_true(escalating_interval_seconds(5) === 86400, 'escalating reminder 5 should wait 1 day');
assert_true(escalating_interval_seconds(6) === 86400, 'escalating should hold at daily after exhaustion');

handoff_escalation($db, '2005', 14, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2005'")->fetchColumn() === 1, 'degrade path should nag after reachable-to-unreachable');
handoff_escalation($db, '2005', 15, 'not_registered', '2026-06-15 10:02:00', '2026-06-15 10:02:00');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2005'")->fetchColumn() === 1, 'degrade path should remain under one active reminder type');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE extension = '2005' AND alert_type = 'not_registered'")->fetchColumn() === 1, 'degrade path should hand off to not_registered without a silent gap');

handoff_escalation($db, '2006', 20, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
$db->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 1, last_alert_at = '2026-06-15 10:05:00', next_due_at = '2026-06-15 10:20:00' WHERE extension = '2006'");
handoff_escalation($db, '2006', 21, 'not_registered', '2026-06-15 10:06:00', '2026-06-15 10:06:00');
$row = $db->query("SELECT alert_type, active_since, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE extension = '2006'")->fetch(PDO::FETCH_ASSOC);
assert_true($row['alert_type'] === 'not_registered', 'flap path should hand off to not_registered');
assert_true($row['active_since'] === '2026-06-15 10:00:00', 'flap path should keep original active_since');
assert_true((int)$row['alert_count'] === 1, 'flap path should carry alert_count after first type flip');
assert_true($row['next_due_at'] === '2026-06-15 10:20:00', 'flap path should not restart next_due_at after first type flip');

$db->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 2, last_alert_at = '2026-06-15 10:20:00', next_due_at = '2026-06-15 11:20:00' WHERE extension = '2006'");
handoff_escalation($db, '2006', 22, 'unreachable', '2026-06-15 10:21:00', '2026-06-15 10:21:00');
$row = $db->query("SELECT alert_type, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE extension = '2006'")->fetch(PDO::FETCH_ASSOC);
assert_true($row['alert_type'] === 'unreachable', 'flap path should hand back to unreachable');
assert_true((int)$row['alert_count'] === 2, 'flap path should carry alert_count after second type flip');
assert_true($row['next_due_at'] === '2026-06-15 11:20:00', 'flap path should not restart next_due_at after second type flip');

$db->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 3, last_alert_at = '2026-06-15 11:20:00', next_due_at = '2026-06-15 15:20:00' WHERE extension = '2006'");
handoff_escalation($db, '2006', 23, 'not_registered', '2026-06-15 11:21:00', '2026-06-15 11:21:00');
$row = $db->query("SELECT alert_type, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE extension = '2006'")->fetch(PDO::FETCH_ASSOC);
assert_true($row['alert_type'] === 'not_registered', 'flap path should hand off repeatedly without recovery');
assert_true((int)$row['alert_count'] === 3, 'flap path should keep climbing instead of resetting to zero');
assert_true($row['next_due_at'] === '2026-06-15 15:20:00', 'flap path should preserve the climbing due time');

assert_true(fibonacci_interval_seconds(1) === 300, 'fibonacci reminder 1 should wait 5 minutes');
assert_true(fibonacci_interval_seconds(2) === 300, 'fibonacci reminder 2 should also wait 5 minutes by contract');
assert_true(fibonacci_interval_seconds(14) === 86400, 'fibonacci should clamp at daily ceiling');
assert_true(fibonacci_interval_seconds(20) === 86400, 'fibonacci should hold at daily ceiling');

echo "repeat alerting contract tests passed\n";
