<?php

declare(strict_types=1);

function assert_true(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function registration_key(string $extension, string $sourceIp, string $uaClass = ''): string {
	$basis = strtolower(trim($extension)) . "\0" . strtolower(trim($sourceIp));
	$uaClass = strtolower(trim(preg_replace('/\s+/', ' ', $uaClass) ?? ''));
	if ($uaClass !== '') {
		$basis .= "\0" . $uaClass;
	}
	return hash('sha256', $basis);
}

function resolve_identity_group(array $items, array $existingState = []): array {
	$usable = [];
	foreach ($items as $item) {
		$ua = strtolower(trim(preg_replace('/\s+/', ' ', (string)($item['user_agent'] ?? '')) ?? ''));
		if ($ua !== '') {
			$usable[$ua] = true;
		}
	}
	ksort($usable);

	$existingClasses = $existingState['classes'] ?? [];
	$existingShared = $existingState['shared'] ?? [];
	$existingNonShared = array_values(array_filter(array_map('strval', $existingClasses), function ($ua) {
		return $ua !== '';
	}));

	$split = [];
	if (count($usable) > 1) {
		$split = array_fill_keys(array_keys($usable), true);
	} elseif (count($usable) === 1 && $existingNonShared) {
		$split = array_fill_keys(array_unique(array_merge(array_keys($usable), $existingNonShared)), true);
	}

	$anchor = null;
	if ($split && $existingShared) {
		foreach ($existingShared as $existing) {
			foreach ($items as $idx => $item) {
				if (!empty($existing['contact_uri']) && $existing['contact_uri'] === ($item['contact_uri'] ?? '')) {
					$anchor = $idx;
					break 2;
				}
			}
		}
		if ($anchor === null) {
			$ranked = [];
			foreach ($items as $idx => $item) {
				$ranked[] = [
					'index' => $idx,
					'ua' => strtolower(trim((string)($item['user_agent'] ?? ''))),
					'contact_uri' => (string)($item['contact_uri'] ?? ''),
				];
			}
			usort($ranked, function ($a, $b) {
				return strcmp($a['ua'], $b['ua']) ?: strcmp($a['contact_uri'], $b['contact_uri']);
			});
			$anchor = (int)$ranked[0]['index'];
		}
	}

	foreach ($items as $idx => $item) {
		$ua = strtolower(trim(preg_replace('/\s+/', ' ', (string)($item['user_agent'] ?? '')) ?? ''));
		$class = ($anchor !== $idx && $ua !== '' && isset($split[$ua])) ? $ua : '';
		$items[$idx]['registration_ua_class'] = $class;
		$items[$idx]['registration_key'] = registration_key($item['extension'], $item['source_ip'], $class);
	}

	return $items;
}

function enrich_for_identity_contract(array $contact, array $registrarDetails): array {
	$exact = null;
	$fallback = [];
	foreach ($registrarDetails as $detail) {
		if (($detail['extension'] ?? '') !== ($contact['extension'] ?? '')) {
			continue;
		}
		if (($detail['contact_uri'] ?? '') !== '' && $detail['contact_uri'] === ($contact['contact_uri'] ?? '')) {
			$exact = $detail;
			break;
		}
		if (($detail['source_ip'] ?? '') !== '' && $detail['source_ip'] === ($contact['source_ip'] ?? '')) {
			$fallback[] = $detail;
		}
	}

	if (is_array($exact)) {
		$contact['source_ip'] = $exact['source_ip'] ?: $contact['source_ip'];
		$contact['user_agent'] = $exact['user_agent'] ?? $contact['user_agent'];
		return $contact;
	}

	foreach ($fallback as $detail) {
		$contact['contact_expires_at'] = $detail['contact_expires_at'] ?? ($contact['contact_expires_at'] ?? null);
	}

	return $contact;
}

function should_auto_disable_absent(array $registration, int $thresholdSeconds, string $now): bool {
	if ($thresholdSeconds <= 0 || (int)($registration['enabled'] ?? 0) !== 1 || !empty($registration['auto_disabled_absent_at'])) {
		return false;
	}
	$lastSeen = strtotime((string)($registration['last_seen_at'] ?? ''));
	$nowTs = strtotime($now);
	return $lastSeen !== false && $nowTs !== false && ($nowTs - $lastSeen) >= $thresholdSeconds;
}

function escalating_interval_seconds(int $alertCount): int {
	$steps = [300, 900, 3600, 14400, 86400];
	return $steps[min(max(0, $alertCount - 1), count($steps) - 1)];
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

function is_still_alertable(string $alertType, string $status): bool {
	return ($alertType === 'unreachable' && $status === 'Unreachable')
		|| ($alertType === 'not_registered' && $status === 'Not registered');
}

function handoff_escalation(PDO $db, int $registrationId, string $registrationKey, string $extension, int $historyId, string $alertType, string $createdAt, string $now): void {
	$stmt = $db->prepare(
		'SELECT registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode
		FROM registrationwatch_alert_escalation
		WHERE registration_id = :registration_id
		ORDER BY active_since ASC, id ASC
		LIMIT 1'
	);
	$stmt->execute([':registration_id' => $registrationId]);
	$existing = $stmt->fetch(PDO::FETCH_ASSOC);

	$activeSince = is_array($existing) && !empty($existing['active_since']) ? $existing['active_since'] : $createdAt;
	$lastAlertAt = is_array($existing) && !empty($existing['last_alert_at']) ? $existing['last_alert_at'] : $now;
	$alertCount = is_array($existing) ? (int)$existing['alert_count'] : 0;
	$nextDueAt = is_array($existing) && !empty($existing['next_due_at']) ? $existing['next_due_at'] : '2026-06-15 10:05:00';

	$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = :registration_id AND alert_type <> :alert_type')
		->execute([':registration_id' => $registrationId, ':alert_type' => $alertType]);

	$db->prepare(
		'INSERT INTO registrationwatch_alert_escalation
			(registration_id, registration_key, extension, history_id, alert_type, active_since, last_alert_at, alert_count, next_due_at, repeat_mode)
		VALUES
			(:registration_id, :registration_key, :extension, :history_id, :alert_type, :active_since, :last_alert_at, :alert_count, :next_due_at, :repeat_mode)
		ON CONFLICT(registration_id, alert_type) DO UPDATE SET
			registration_key = excluded.registration_key,
			extension = excluded.extension,
			history_id = excluded.history_id,
			active_since = excluded.active_since,
			last_alert_at = excluded.last_alert_at,
			alert_count = excluded.alert_count,
			next_due_at = excluded.next_due_at,
			repeat_mode = excluded.repeat_mode'
	)->execute([
		':registration_id' => $registrationId,
		':registration_key' => $registrationKey,
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

function storm_contract_decision(array $alerts, $threshold): array {
	$threshold = trim((string)$threshold);
	$threshold = $threshold !== '' && ctype_digit($threshold) ? (int)$threshold : 0;
	if ($threshold <= 0 || count($alerts) < $threshold) {
		return ['individuals' => $alerts, 'summaries' => []];
	}
	$recipients = array_values(array_unique(array_column($alerts, 'recipient')));
	return ['individuals' => [], 'summaries' => $recipients];
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
	'CREATE TABLE registrationwatch_alert_history (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_id INTEGER,
		registration_key TEXT,
		extension TEXT NOT NULL,
		contact_uri TEXT,
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
		registration_id INTEGER NOT NULL,
		registration_key TEXT NOT NULL,
		extension TEXT NOT NULL,
		history_id INTEGER NOT NULL,
		alert_type TEXT NOT NULL,
		active_since TEXT NOT NULL,
		last_alert_at TEXT,
		alert_count INTEGER NOT NULL DEFAULT 0,
		next_due_at TEXT NOT NULL,
		repeat_mode TEXT NOT NULL,
		UNIQUE (registration_id, alert_type)
	)'
);
$db->exec(
	'CREATE TABLE registrationwatch_registrations (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		registration_key TEXT NOT NULL UNIQUE,
		extension TEXT NOT NULL,
		source_ip TEXT NOT NULL,
		registration_ua_class TEXT NOT NULL DEFAULT "",
		enabled INTEGER NOT NULL,
		auto_disabled_absent_at TEXT,
		repeat_mode TEXT,
		last_known_status TEXT NOT NULL,
		last_seen_at TEXT
	)'
);

$keyA = registration_key('2001', '198.51.100.10');
$keyB = registration_key('2001', '198.51.100.11');
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$keyA, '2001', '198.51.100.10', 1, 'escalating', 'Reachable']);
$regA = (int)$db->lastInsertId();
$db->prepare('INSERT INTO registrationwatch_registrations (registration_key, extension, source_ip, enabled, repeat_mode, last_known_status) VALUES (?, ?, ?, ?, ?, ?)')
	->execute([$keyB, '2001', '198.51.100.11', 1, null, 'Unreachable']);
$regB = (int)$db->lastInsertId();
assert_true($regA !== $regB, 'two source IPs under one extension should be separate watched registrations');

$insertAlert = $db->prepare(
	'INSERT OR IGNORE INTO registrationwatch_alert_history
		(registration_id, registration_key, extension, history_id, reminder_n, alert_type, status, recipient, result)
	VALUES
		(:registration_id, :registration_key, :extension, :history_id, :reminder_n, :alert_type, :status, :recipient, :result)'
);
$baseAlert = [
	':registration_id' => $regB,
	':registration_key' => $keyB,
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

handoff_escalation($db, $regB, $keyB, '2001', 20, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetchColumn() === 1, 'unreachable sibling should have its own escalation');
assert_true(is_still_alertable('unreachable', 'Reachable') === false, 'reachable status should recover unreachable registration');
assert_true(is_still_alertable('not_registered', 'Not registered') === true, 'missing same registration remains alertable as not registered');

$live = [$keyA => 'Reachable'];
$statusForMissingB = isset($live[$keyB]) ? $live[$keyB] : 'Not registered';
assert_true($statusForMissingB === 'Not registered', 'reachable sibling must not recover missing registration');

$db->prepare('DELETE FROM registrationwatch_alert_escalation WHERE registration_id = ? AND alert_type = ?')->execute([$regB, 'unreachable']);
assert_true((int)$db->query("SELECT COUNT(*) FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetchColumn() === 0, 'recovery should clear only same registration escalation');

handoff_escalation($db, $regB, $keyB, '2001', 21, 'unreachable', '2026-06-15 10:00:00', '2026-06-15 10:00:00');
$db->exec("UPDATE registrationwatch_alert_escalation SET alert_count = 2, next_due_at = '2026-06-15 11:20:00' WHERE registration_id = {$regB}");
handoff_escalation($db, $regB, $keyB, '2001', 22, 'not_registered', '2026-06-15 10:21:00', '2026-06-15 10:21:00');
$row = $db->query("SELECT alert_type, alert_count, next_due_at FROM registrationwatch_alert_escalation WHERE registration_id = {$regB}")->fetch(PDO::FETCH_ASSOC);
assert_true($row['alert_type'] === 'not_registered', 'flap handoff should change type for same registration');
assert_true((int)$row['alert_count'] === 2, 'flap handoff should preserve alert_count for same registration');
assert_true($row['next_due_at'] === '2026-06-15 11:20:00', 'flap handoff should preserve next due time for same registration');

$db->exec("UPDATE registrationwatch_registrations SET repeat_mode = 'daily' WHERE id = {$regB}");
$modeA = $db->query("SELECT COALESCE(repeat_mode, 'global') FROM registrationwatch_registrations WHERE id = {$regA}")->fetchColumn();
$modeB = $db->query("SELECT repeat_mode FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn();
assert_true($modeA === 'escalating', 'per-registration override should not affect sibling registration');
assert_true($modeB === 'daily', 'per-registration override should apply to selected registration');

$sameUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'Phone/1'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'Phone/1'],
]);
assert_true($sameUa[0]['registration_key'] === $sameUa[1]['registration_key'], 'same extension/IP/same UA should collapse');
$differentUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'PhoneA'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
]);
assert_true($differentUa[0]['registration_key'] !== $differentUa[1]['registration_key'], 'different usable UAs behind same IP should split');
$missingUa = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => ''],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
]);
assert_true($missingUa[0]['registration_key'] === $missingUa[1]['registration_key'], 'missing UA should collapse and never force a split');
$anchored = resolve_identity_group([
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => 'PhoneA'],
	['extension' => '2002', 'source_ip' => '203.0.113.10', 'contact_uri' => 'sip:2002@203.0.113.10:5062', 'user_agent' => 'PhoneB'],
], ['classes' => [''], 'shared' => [['contact_uri' => 'sip:2002@203.0.113.10:5060', 'user_agent' => null]]]);
assert_true($anchored[0]['registration_ua_class'] === '', 'incumbent shared registration should keep bare key on later conflict');
assert_true($anchored[1]['registration_ua_class'] === 'phoneb', 'conflicting newcomer should get suffixed key');

$natContact = enrich_for_identity_contract(
	[
		'extension' => '2003',
		'contact_uri' => 'sip:2003@10.0.0.44:5060',
		'source_ip' => '10.0.0.44',
		'user_agent' => null,
	],
	[
		[
			'extension' => '2003',
			'contact_uri' => 'sip:2003@10.0.0.44:5060',
			'source_ip' => '198.51.100.44',
			'user_agent' => 'NatPhone/7',
		],
	]
);
$natResolved = resolve_identity_group([$natContact]);
assert_true($natResolved[0]['source_ip'] === '198.51.100.44', 'exact registrar via_addr should replace parsed contact host before identity');
assert_true($natResolved[0]['registration_key'] === registration_key('2003', '198.51.100.44'), 'NAT registration should key on authoritative via_addr');

$fallbackContact = enrich_for_identity_contract(
	[
		'extension' => '2004',
		'contact_uri' => 'sip:2004@198.51.100.55:5060',
		'source_ip' => '198.51.100.55',
		'user_agent' => null,
	],
	[
		[
			'extension' => '2004',
			'contact_uri' => 'sip:2004@198.51.100.55:5099',
			'source_ip' => '198.51.100.55',
			'user_agent' => 'SiblingPhone/9',
			'contact_expires_at' => '2026-06-15 11:00:00',
		],
	]
);
assert_true(($fallbackContact['user_agent'] ?? null) === null, 'fallback enrichment must not copy a sibling UA into identity');

$storm = storm_contract_decision([
	['registration_id' => $regA, 'extension' => '2001', 'recipient' => 'admin@example.invalid'],
	['registration_id' => $regB, 'extension' => '2001', 'recipient' => 'admin@example.invalid'],
], 2);
assert_true(count($storm['summaries']) === 1 && count($storm['individuals']) === 0, 'storm threshold should count sibling registrations separately');

$lockHeld = false;
$historyRows = 0;
$db->exec(
	'CREATE TABLE registrationwatch_reconcile_contract_history (
		registration_id INTEGER NOT NULL,
		from_state TEXT NOT NULL,
		to_state TEXT NOT NULL,
		created_at TEXT NOT NULL,
		UNIQUE (registration_id, from_state, to_state)
	)'
);
$reconcileOnce = function () use (&$lockHeld, &$historyRows): void {
	if ($lockHeld) {
		return;
	}
	$lockHeld = true;
	try {
		if ($historyRows === 0) {
			$historyRows++;
		}
	} finally {
		$lockHeld = false;
	}
};
$lockHeld = true;
$reconcileOnce();
assert_true($historyRows === 0, 'second reconcile should skip while the reconcile lock is held');
$lockHeld = false;
$reconcileOnce();
$reconcileOnce();
assert_true($historyRows === 1, 'serial reconciles should not duplicate one transition row once state has advanced');
$insertTransition = $db->prepare(
	'INSERT OR IGNORE INTO registrationwatch_reconcile_contract_history
		(registration_id, from_state, to_state, created_at)
	VALUES (?, ?, ?, ?)'
);
$insertTransition->execute([$regB, 'Reachable', 'Not registered', '2026-06-15 10:00:00']);
$insertTransition->execute([$regB, 'Reachable', 'Not registered', '2026-06-15 10:00:00']);
assert_true((int)$db->query('SELECT COUNT(*) FROM registrationwatch_reconcile_contract_history')->fetchColumn() === 1, 'locked reconcile contract should produce one transition history row for one state change');

$db->exec("UPDATE registrationwatch_registrations SET enabled = 1, auto_disabled_absent_at = NULL, last_seen_at = '2026-05-01 00:00:00', last_known_status = 'Not registered' WHERE id = {$regB}");
$absent = $db->query("SELECT enabled, auto_disabled_absent_at, last_seen_at FROM registrationwatch_registrations WHERE id = {$regB}")->fetch(PDO::FETCH_ASSOC);
assert_true(should_auto_disable_absent($absent, 2592000, '2026-06-15 10:00:00'), 'registration absent beyond threshold should qualify for auto-disable');
$db->prepare('UPDATE registrationwatch_registrations SET enabled = 0, auto_disabled_absent_at = ? WHERE id = ?')->execute(['2026-06-15 10:00:00', $regB]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn() === 0, 'auto-disabled absent registration should stop alert eligibility');
$db->prepare('UPDATE registrationwatch_registrations SET enabled = CASE WHEN auto_disabled_absent_at IS NOT NULL THEN 1 ELSE enabled END, auto_disabled_absent_at = NULL WHERE id = ?')->execute([$regB]);
assert_true((int)$db->query("SELECT enabled FROM registrationwatch_registrations WHERE id = {$regB}")->fetchColumn() === 1, 'returning auto-disabled registration should re-enable automatically');

assert_true(escalating_interval_seconds(1) === 300, 'escalating reminder 1 should wait 5 minutes');
assert_true(escalating_interval_seconds(6) === 86400, 'escalating should hold at daily after exhaustion');
assert_true(fibonacci_interval_seconds(1) === 300, 'fibonacci reminder 1 should wait 5 minutes');
assert_true(fibonacci_interval_seconds(14) === 86400, 'fibonacci should clamp at daily ceiling');

echo "repeat alerting contract tests passed\n";
