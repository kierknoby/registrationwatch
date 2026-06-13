<?php
/**
 * EndPoint Monitor for FreePBX 17.
 *
 * PJSIP endpoint discovery and current status visibility.
 *
 * @copyright 2026 20 Telecom Ltd (trading as 20tele.com)
 * @license   GPLv3+
 */

namespace FreePBX\modules;

class Endpointmonitor implements \BMO {

	/** Fallback only. Authoritative version lives in module.xml. */
	const VERSION = '1.1.1';

	const STATUS_REACHABLE = 'Reachable';
	const STATUS_UNREACHABLE = 'Unreachable';
	const STATUS_REGISTERED_NO_QUALIFY = 'Registered (no qualify)';
	const STATUS_UNKNOWN = 'Unknown';
	const STATUS_NOT_REGISTERED = 'Not registered';
	const CSRF_SESSION_KEY = 'endpointmonitor_csrf_token';
	const ALERT_TIMING_MAX_SECONDS = 86400;
	const ALERT_STALE_TRANSITION_MAX_SECONDS = 300;

	private $settingsDefaults = [
		'alert_enabled' => '0',
		'alert_recipients' => '',
		'ui_show_limit' => '6',
		'alert_on_unreachable' => '1',
		'alert_on_not_registered' => '1',
		'alert_on_recovery' => '1',
		'debounce_seconds' => '0',
		'repeat_suppression_seconds' => '0',
		'trusted_vpn_networks' => '',
		'topology_poll_interval_seconds' => '10',
		'status_history_prune_policy' => 'never',
		'alert_history_prune_policy' => 'never',
	];

	/** @var \FreePBX */
	private $FreePBX;

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->FreePBX = $freepbx;
	}

	public function install(): void {}
	public function uninstall(): void {}

	public function backup(): array {
		$backup = [
			'settings' => [],
			'endpoints' => [],
		];

		try {
			$db = $this->db();

			// Backup settings
			$stmt = $db->query('SELECT setting_key, setting_value FROM endpointmonitor_settings');
			if ($stmt) {
				$backup['settings'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			}

			// Backup endpoints
			$stmt = $db->query('SELECT * FROM endpointmonitor_endpoints');
			if ($stmt) {
				$backup['endpoints'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
			}
		} catch (\Exception $e) {
			$this->logError('Backup failed: ' . $e->getMessage());
			return [];
		}

		return $backup;
	}

	public function restore($backup): void {
		if (!is_array($backup) || empty($backup)) {
			return;
		}

		try {
			$db = $this->db();

			// Restore settings (upsert to preserve existing values)
			if (isset($backup['settings']) && is_array($backup['settings'])) {
				foreach ($backup['settings'] as $row) {
					$stmt = $db->prepare(
						'INSERT INTO endpointmonitor_settings (setting_key, setting_value, updated_at)
						VALUES (:setting_key, :setting_value, :updated_at)
						ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
					);
					$stmt->execute([
						':setting_key' => $row['setting_key'],
						':setting_value' => $row['setting_value'],
						':updated_at' => $this->now(),
					]);
				}
			}

			// Restore endpoints (preserve discovery flags)
			if (isset($backup['endpoints']) && is_array($backup['endpoints'])) {
				foreach ($backup['endpoints'] as $row) {
					$stmt = $db->prepare(
						'INSERT INTO endpointmonitor_endpoints
							(extension, description, notes, notes_updated_at, enabled, discovered, last_known_status, contact_uri,
							 source_ip, source_port, transport, user_agent, device_name, firmware_version,
							 contact_expires_at, qualify_frequency, last_heartbeat_at, latency_ms,
							 last_seen_at, last_checked_at, first_discovered_at, last_discovered_at,
							 created_at, updated_at)
						VALUES
							(:extension, :description, :notes, :notes_updated_at, :enabled, :discovered, :last_known_status, :contact_uri,
							 :source_ip, :source_port, :transport, :user_agent, :device_name, :firmware_version,
							 :contact_expires_at, :qualify_frequency, :last_heartbeat_at, :latency_ms,
							 :last_seen_at, :last_checked_at, :first_discovered_at, :last_discovered_at,
							 :created_at, :updated_at)
						ON DUPLICATE KEY UPDATE
							description = VALUES(description),
							notes = VALUES(notes),
							notes_updated_at = VALUES(notes_updated_at),
							enabled = VALUES(enabled),
							last_known_status = VALUES(last_known_status),
							contact_uri = VALUES(contact_uri),
							source_ip = VALUES(source_ip),
							source_port = VALUES(source_port),
							transport = VALUES(transport),
							user_agent = VALUES(user_agent),
							device_name = VALUES(device_name),
							firmware_version = VALUES(firmware_version),
							contact_expires_at = VALUES(contact_expires_at),
							qualify_frequency = VALUES(qualify_frequency),
							last_heartbeat_at = VALUES(last_heartbeat_at),
							latency_ms = VALUES(latency_ms),
							last_seen_at = VALUES(last_seen_at),
							last_checked_at = VALUES(last_checked_at),
							last_discovered_at = VALUES(last_discovered_at),
							updated_at = VALUES(updated_at)'
					);
					$stmt->execute([
						':extension' => $row['extension'],
						':description' => $row['description'] ?? null,
						':notes' => isset($row['notes']) ? substr((string)$row['notes'], 0, 48) : '',
						':notes_updated_at' => $row['notes_updated_at'] ?? null,
						':enabled' => $row['enabled'] ?? 1,
						':discovered' => $row['discovered'] ?? 1,
						':last_known_status' => $row['last_known_status'] ?? self::STATUS_UNKNOWN,
						':contact_uri' => $row['contact_uri'] ?? null,
						':source_ip' => $row['source_ip'] ?? null,
						':source_port' => $row['source_port'] ?? null,
						':transport' => $row['transport'] ?? null,
						':user_agent' => $row['user_agent'] ?? null,
						':device_name' => $row['device_name'] ?? null,
						':firmware_version' => $row['firmware_version'] ?? null,
						':contact_expires_at' => $row['contact_expires_at'] ?? null,
						':qualify_frequency' => $row['qualify_frequency'] ?? null,
						':last_heartbeat_at' => $row['last_heartbeat_at'] ?? null,
						':latency_ms' => $row['latency_ms'] ?? null,
						':last_seen_at' => $row['last_seen_at'] ?? null,
						':last_checked_at' => $row['last_checked_at'] ?? null,
						':first_discovered_at' => $row['first_discovered_at'] ?? null,
						':last_discovered_at' => $row['last_discovered_at'] ?? null,
						':created_at' => $row['created_at'] ?? $this->now(),
						':updated_at' => $this->now(),
					]);
				}
			}
		} catch (\Exception $e) {
			$this->logError('Restore failed: ' . $e->getMessage());
		}
	}
	public function doConfigPageInit($page): void {}

	public function getVersion(): string {
		try {
			$info = \FreePBX::Modules()->getInfo('endpointmonitor');
			if (isset($info['endpointmonitor']['version'])) {
				return (string)$info['endpointmonitor']['version'];
			}
		} catch (\Exception $e) {
			// Module metadata may be unavailable during early install.
		}

		return self::VERSION;
	}

	public function showPage(): string {
		$data = $this->getPageData(false);

		return load_view(__DIR__ . '/views/main.php', [
			'moduleVersion' => $this->getVersion(),
			'endpoints' => $data['endpoints'],
			'statusHistory' => $data['statusHistory'],
			'alertSettings' => $data['alertSettings'],
			'pruneSettings' => $this->getPruneSettings(),
			'alertHistory' => $data['alertHistory'],
			'lastRefresh' => $data['lastRefresh'],
			'refreshError' => $data['refreshError'],
			'emailStatus' => $data['emailStatus'],
			'pollIntervalSeconds' => $data['pollIntervalSeconds'],
			'csrfToken' => $this->createCsrfToken(),
		]);
	}

	public function ajaxRequest($req, &$setting): bool {
		switch ($req) {
			case 'refresh':
			case 'setenabled':
			case 'savenotes':
			case 'saveshowlimit':
			case 'savealerts':
			case 'savetopology':
			case 'testemail':
			case 'gettopology':
			case 'saveprunepolicy':
			case 'deletestatushistoryrow':
			case 'deletealerthistoryrow':
				return true;
		}

		return false;
	}

	public function ajaxHandler(): array {
		$command = isset($_REQUEST['command']) ? (string)$_REQUEST['command'] : '';

		// CSRF protection: required for all state-modifying operations
		if (!$this->validateCsrfToken()) {
			return ['status' => false, 'message' => _('Invalid security token. Please reload the page and try again.')];
		}

		// Access control relies on FreePBX authenticated admin context.
		// See README TODO for granular ACL integration roadmap.

		switch ($command) {
			case 'refresh':
				return $this->handleRefresh();
			case 'setenabled':
				return $this->handleSetEnabled();
			case 'savenotes':
				return $this->handleSaveNotes();
			case 'saveshowlimit':
				return $this->handleSaveShowLimit();
			case 'savealerts':
				return $this->handleSaveAlerts();
			case 'savetopology':
				return $this->handleSaveTopology();
			case 'testemail':
				return $this->handleTestEmail();
			case 'gettopology':
				return $this->handleGetTopology();
			case 'saveprunepolicy':
				return $this->handleSavePrunePolicy();
			case 'deletestatushistoryrow':
				return $this->handleDeleteStatusHistoryRow();
			case 'deletealerthistoryrow':
				return $this->handleDeleteAlertHistoryRow();
		}

		return ['status' => false, 'message' => _('Unknown command')];
	}

	private function handleRefresh(): array {
		try {
			$data = $this->getPageData(true);

			return [
				'status' => $data['refreshError'] === '',
				'message' => $data['refreshError'] === '' ? _('EndPoint status refreshed.') : $data['refreshError'],
				'endpoints' => $data['endpoints'],
				'statusHistory' => $data['statusHistory'],
				'alertHistory' => $data['alertHistory'],
				'lastRefresh' => $data['lastRefresh'],
			];
		} catch (\Exception $e) {
			$message = _('Failed to refresh EndPoint status. Please check the system logs.');
			$this->logError('Refresh failed: ' . $e->getMessage());
			return ['status' => false, 'message' => $message];
		}
	}

	private function handleSetEnabled(): array {
		$extension = isset($_REQUEST['extension']) ? trim((string)$_REQUEST['extension']) : '';
		$enabled = !empty($_REQUEST['enabled']) ? 1 : 0;

		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing EndPoint.')];
		}

		$this->syncDiscoveredEndpoints();
		$db = $this->db();
		$stmt = $db->prepare('UPDATE endpointmonitor_endpoints SET enabled = :enabled, updated_at = :updated_at WHERE extension = :extension');
		$stmt->execute([
			':enabled' => $enabled,
			':updated_at' => $this->now(),
			':extension' => $extension,
		]);

		return [
			'status' => true,
			'message' => $enabled ? _('EndPoint selected.') : _('EndPoint selection cleared.'),
			'endpoint' => $extension,
			'enabled' => $enabled,
		];
	}

	private function handleSaveNotes(): array {
		$extension = isset($_REQUEST['extension']) ? trim((string)$_REQUEST['extension']) : '';
		$notes = isset($_REQUEST['notes']) ? (string)$_REQUEST['notes'] : '';

		if ($extension === '') {
			return ['status' => false, 'message' => _('Missing EndPoint.')];
		}

		$notes = trim(preg_replace('/\s+/', ' ', $notes));
		if (function_exists('mb_substr')) {
			$notes = mb_substr($notes, 0, 48);
		} else {
			$notes = substr($notes, 0, 48);
		}

		$now = $this->now();
		$notesUpdatedAt = $notes === '' ? null : $now;

		$stmt = $this->db()->prepare(
			'UPDATE endpointmonitor_endpoints
			SET notes = :notes,
				notes_updated_at = :notes_updated_at,
				updated_at = :updated_at
			WHERE extension = :extension'
		);
		$stmt->execute([
			':notes' => $notes,
			':notes_updated_at' => $notesUpdatedAt,
			':updated_at' => $now,
			':extension' => $extension,
		]);

		return [
			'status' => true,
			'message' => $notes === '' ? _('EndPoint note cleared.') : _('EndPoint note saved.'),
			'extension' => $extension,
			'notes' => $notes,
			'notes_updated_at' => $notesUpdatedAt,
		];
	}

	private function handleSaveShowLimit(): array {
		$showLimit = isset($_REQUEST['show_limit']) ? strtolower(trim((string)$_REQUEST['show_limit'])) : '6';
		$allowed = ['6', '30', '60', '120', 'all'];

		if (!in_array($showLimit, $allowed, true)) {
			$showLimit = '6';
		}

		$now = $this->now();
		$stmt = $this->db()->prepare(
			'INSERT INTO endpointmonitor_settings (setting_key, setting_value, updated_at)
			VALUES (:setting_key, :setting_value, :updated_at)
			ON DUPLICATE KEY UPDATE
				setting_value = VALUES(setting_value),
				updated_at = VALUES(updated_at)'
		);
		$stmt->execute([
			':setting_key' => 'ui_show_limit',
			':setting_value' => $showLimit,
			':updated_at' => $now,
		]);

		return [
			'status' => true,
			'message' => _('Show limit saved.'),
			'show_limit' => $showLimit,
		];
	}

	private function handleSaveAlerts(): array {
		$recipientsRaw = isset($_REQUEST['alert_recipients']) ? (string)$_REQUEST['alert_recipients'] : '';
		$recipients = $this->normaliseRecipients($recipientsRaw);
		if ($recipientsRaw !== '' && !$recipients) {
			return ['status' => false, 'message' => _('Enter at least one valid email recipient.')];
		}
		$debounceSeconds = $this->normaliseAlertTimingSeconds('debounce_seconds', $this->settingsDefaults['debounce_seconds']);
		if ($debounceSeconds === null) {
			return ['status' => false, 'message' => _('Debounce delay must be a whole number from 0 to 86400 seconds.')];
		}
		$repeatSuppressionSeconds = $this->normaliseAlertTimingSeconds('repeat_suppression_seconds', $this->settingsDefaults['repeat_suppression_seconds']);
		if ($repeatSuppressionSeconds === null) {
			return ['status' => false, 'message' => _('Repeat suppression must be a whole number from 0 to 86400 seconds.')];
		}

		$settings = [
			'alert_enabled' => !empty($_REQUEST['alert_enabled']) ? '1' : '0',
			'alert_recipients' => implode(', ', $recipients),
			'alert_on_unreachable' => !empty($_REQUEST['alert_on_unreachable']) ? '1' : '0',
			'alert_on_not_registered' => !empty($_REQUEST['alert_on_not_registered']) ? '1' : '0',
			'alert_on_recovery' => !empty($_REQUEST['alert_on_recovery']) ? '1' : '0',
			'debounce_seconds' => (string)$debounceSeconds,
			'repeat_suppression_seconds' => (string)$repeatSuppressionSeconds,
		];

		foreach ($settings as $key => $value) {
			$this->setSetting($key, $value);
		}

		return [
			'status' => true,
			'message' => _('Alert settings saved.'),
			'alertSettings' => $this->getAlertSettings(),
		];
	}

	private function handleSaveTopology(): array {
		$vpnNetworks = isset($_REQUEST['trusted_vpn_networks']) ? (string)$_REQUEST['trusted_vpn_networks'] : '';
		$pollInterval = (int)($_REQUEST['topology_poll_interval_seconds'] ?? 10);

		// Enforce minimum 5 seconds if not disabled (0 or negative)
		if ($pollInterval > 0 && $pollInterval < 5) {
			$pollInterval = 5;
		} elseif ($pollInterval < 0) {
			$pollInterval = 0;
		}

		$settings = [
			'trusted_vpn_networks' => $vpnNetworks,
			'topology_poll_interval_seconds' => (string)$pollInterval,
		];

		foreach ($settings as $key => $value) {
			$this->setSetting($key, $value);
		}

		return [
			'status' => true,
			'message' => _('Topology settings saved.'),
			'alertSettings' => $this->getAlertSettings(),
		];
	}

	private function handleTestEmail(): array {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);
		if (!$recipients) {
			return ['status' => false, 'message' => _('No valid alert recipients are configured.')];
		}

		$now = $this->now();
		$sent = 0;
		$failed = 0;
		foreach ($recipients as $recipient) {
			$subject = _('EndPoint Monitor: test email');
			$message = "EndPoint Monitor test email\n\nTime: " . $now . "\nSource: manual test\n\nPlease note: Email \"From:\" Address has been configured in Advanced Settings.\n";
			$result = $this->sendEmail($recipient, $subject, $message);
			if ($result['status']) {
				$sent++;
			} else {
				$failed++;
			}
			$this->insertAlertHistory([
				'extension' => '',
				'history_id' => null,
				'alert_type' => 'test',
				'status' => 'test',
				'recipient' => $recipient,
				'subject' => $subject,
				'message' => $message,
				'sent_at' => $now,
				'result' => $result['status'] ? 'sent' : 'failed',
				'error' => $result['status'] ? null : $result['message'],
			]);
		}

		$status = $sent > 0;
		if ($sent === 0) {
			$message = sprintf(_('Test email failed for %d recipient(s).'), $failed);
		} elseif ($failed > 0) {
			$message = sprintf(_('Test email accepted by local mailer for %d recipient(s); %d failed. Delivery is not confirmed.'), $sent, $failed);
		} else {
			$message = sprintf(_('Test email accepted by local mailer for %d recipient(s). Delivery is not confirmed.'), $sent);
		}

		return [
			'status' => $status,
			'message' => $message,
			'alertHistory' => $this->getAlertHistory(),
		];
	}

	private function handleGetTopology(): array {
		// Endpoint map read-only action: returns stored endpoint status only.
		// Do not trigger discovery, reconciliation, history writes, or alerts here.
		try {
			return [
				'status' => true,
				'endpoints' => $this->getEndpointMapRows(),
				'timestamp' => $this->now(),
			];
		} catch (\Exception $e) {
			$this->logWarning('Endpoint map retrieval failed: ' . $e->getMessage());
			return [
				'status' => false,
				'message' => _('Unable to load EndPoint map.'),
				'endpoints' => [],
			];
		}
	}

	private function handleSavePrunePolicy(): array {
		try {
			$historyType = isset($_REQUEST['history_type']) ? strtolower(trim((string)$_REQUEST['history_type'])) : '';
			$policy = $this->normalisePrunePolicy(isset($_REQUEST['policy']) ? (string)$_REQUEST['policy'] : 'never');
			$confirmed = !empty($_REQUEST['confirmed']);

			if (!in_array($historyType, ['status', 'alert'], true)) {
				return ['status' => false, 'message' => _('Unknown history table.')];
			}

			if ($policy !== 'never' && !$confirmed) {
				return ['status' => false, 'message' => _('Confirm permanent deletion before applying pruning.')];
			}

			$settingKey = $historyType === 'status' ? 'status_history_prune_policy' : 'alert_history_prune_policy';
			$this->setSetting($settingKey, $policy);

			$deleted = 0;
			if ($policy !== 'never') {
				$result = $this->applyHistoryPruning($historyType, $policy);
				$deleted = $result[$historyType] ?? 0;
			}

			$message = $policy === 'never'
				? _('History pruning disabled. No rows were deleted.')
				: sprintf(_('History pruning policy saved. Permanently deleted %d older row(s).'), $deleted);

			$response = [
				'status' => true,
				'message' => $message,
				'history_type' => $historyType,
				'policy' => $policy,
				'deleted' => $deleted,
				'pruneSettings' => $this->getPruneSettings(),
			];

			if ($historyType === 'status') {
				$response['statusHistory'] = $this->getStatusHistory();
			} else {
				$response['alertHistory'] = $this->getAlertHistory();
			}

			return $response;
		} catch (\Exception $e) {
			$this->logError('History pruning failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to update history pruning. Please check the system logs.')];
		}
	}

	private function handleDeleteStatusHistoryRow(): array {
		try {
			$id = $this->positiveRequestId('id');
			if ($id <= 0 || empty($_REQUEST['confirmed'])) {
				return ['status' => false, 'message' => _('Confirm the selected Status History row deletion.')];
			}

			$stmt = $this->db()->prepare('DELETE FROM endpointmonitor_status_history WHERE id = :id LIMIT 1');
			$stmt->execute([':id' => $id]);
			$deleted = $stmt->rowCount();

			return [
				'status' => true,
				'message' => $deleted > 0 ? _('Status History row permanently deleted.') : _('Status History row was not found.'),
				'deleted' => $deleted,
				'statusHistory' => $this->getStatusHistory(),
			];
		} catch (\Exception $e) {
			$this->logError('Status History row delete failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to delete Status History row. Please check the system logs.')];
		}
	}

	private function handleDeleteAlertHistoryRow(): array {
		try {
			$id = $this->positiveRequestId('id');
			if ($id <= 0 || empty($_REQUEST['confirmed'])) {
				return ['status' => false, 'message' => _('Confirm the selected Alert History row deletion.')];
			}

			$stmt = $this->db()->prepare('DELETE FROM endpointmonitor_alert_history WHERE id = :id LIMIT 1');
			$stmt->execute([':id' => $id]);
			$deleted = $stmt->rowCount();

			return [
				'status' => true,
				'message' => $deleted > 0 ? _('Alert History row permanently deleted.') : _('Alert History row was not found.'),
				'deleted' => $deleted,
				'alertHistory' => $this->getAlertHistory(),
			];
		} catch (\Exception $e) {
			$this->logError('Alert History row delete failed: ' . $e->getMessage());
			return ['status' => false, 'message' => _('Unable to delete Alert History row. Please check the system logs.')];
		}
	}

	private function getPageData(bool $refreshStatus): array {
		$refreshError = '';
		if ($refreshStatus) {
			try {
				$this->reconcileCurrentStatus();
			} catch (\Exception $e) {
				$this->logError('Status reconciliation failed: ' . $e->getMessage());
				$refreshError = _('Unable to reconcile EndPoint status. Please check the system logs.');
			}
		}

		return [
			'endpoints' => $this->getStoredEndpoints(),
			'statusHistory' => $this->getStatusHistory(),
			'alertSettings' => $this->getAlertSettings(),
			'alertHistory' => $this->getAlertHistory(),
			'lastRefresh' => $this->getLastRefreshTime(),
			'emailStatus' => $this->getEmailStatus(),
			'pollIntervalSeconds' => $this->getPollInterval(),
			'refreshError' => $refreshError,
		];
	}

	private function getPollInterval(): int {
		$settings = $this->getAlertSettings();
		$interval = (int)($settings['topology_poll_interval_seconds'] ?? 10);
		// Enforce minimum 5 seconds, default 10 seconds
		if ($interval < 5 && $interval > 0) {
			return 5;
		}
		// 0 or negative means disabled
		if ($interval <= 0) {
			return 0;
		}
		return $interval;
	}
	private function syncDiscoveredEndpoints(): void {
		$now = $this->now();
		$discovered = $this->discoverPjsipEndpoints();
		$db = $this->db();

		foreach ($discovered as $endpoint) {
			$stmt = $db->prepare('SELECT id FROM endpointmonitor_endpoints WHERE extension = :extension');
			$stmt->execute([':extension' => $endpoint['extension']]);
			$id = $stmt->fetchColumn();

			if ($id) {
				$update = $db->prepare(
					'UPDATE endpointmonitor_endpoints
					SET description = :description,
						discovered = 1,
						last_discovered_at = :last_discovered_at,
						updated_at = :updated_at
					WHERE extension = :extension'
				);
				$update->execute([
					':description' => $endpoint['description'],
					':last_discovered_at' => $now,
					':updated_at' => $now,
					':extension' => $endpoint['extension'],
				]);
				continue;
			}

			$insert = $db->prepare(
				'INSERT INTO endpointmonitor_endpoints
					(extension, description, enabled, discovered, last_known_status, created_at, updated_at, first_discovered_at, last_discovered_at)
				VALUES
					(:extension, :description, 0, 1, :last_known_status, :created_at, :updated_at, :first_discovered_at, :last_discovered_at)'
			);
			$insert->execute([
				':extension' => $endpoint['extension'],
				':description' => $endpoint['description'],
				':last_known_status' => self::STATUS_UNKNOWN,
				':created_at' => $now,
				':updated_at' => $now,
				':first_discovered_at' => $now,
				':last_discovered_at' => $now,
			]);
		}

		$extensions = array_map(function ($endpoint) {
			return $endpoint['extension'];
		}, $discovered);

		$existing = $this->getStoredEndpoints();
		foreach ($existing as $row) {
			if (in_array($row['extension'], $extensions, true)) {
				continue;
			}

			$stmt = $db->prepare(
				'UPDATE endpointmonitor_endpoints
				SET discovered = 0,
					contact_uri = NULL,
					latency_ms = NULL,
					last_checked_at = :last_checked_at,
					updated_at = :updated_at
				WHERE extension = :extension'
			);
			$stmt->execute([
				':last_checked_at' => $now,
				':updated_at' => $now,
				':extension' => $row['extension'],
			]);
		}
	}

	private function discoverPjsipEndpoints(): array {
		if (!$this->tableExists('devices') && !$this->tableExists('users')) {
			return [];
		}

		// FreePBX/PBXact 17 does not always store keyword=type/data=endpoint
		// rows in pjsip. Treat pjsip rows as supporting evidence only; the
		// FreePBX devices table is the authoritative source for extension devices.
		$pjsipEndpointIds = $this->getPjsipEndpointIds();
		$descriptions = $this->getEndpointDescriptions();
		$seen = [];
		$endpoints = [];
		$ids = [];

		if ($this->tableExists('devices')) {
			$stmt = $this->db()->query("SELECT id FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> '' ORDER BY id");
			$ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
		}

		if (!$ids && $this->tableExists('users')) {
			$stmt = $this->db()->query("SELECT extension FROM users WHERE extension <> '' ORDER BY extension");
			$ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
			if ($pjsipEndpointIds) {
				$ids = array_filter($ids, function ($id) use ($pjsipEndpointIds) {
					return isset($pjsipEndpointIds[trim((string)$id)]);
				});
			}
		}

		foreach ($ids as $id) {
			$extension = trim((string)$id);
			if ($extension === '' || isset($seen[$extension])) {
				continue;
			}
			$seen[$extension] = true;
			$endpoints[] = [
				'extension' => $extension,
				'description' => $descriptions[$extension] ?? '',
			];
		}

		return $endpoints;
	}

	private function getPjsipEndpointIds(): array {
		if (!$this->tableExists('pjsip')) {
			return [];
		}

		$stmt = $this->db()->query("SELECT DISTINCT id FROM pjsip WHERE keyword = 'type' AND data = 'endpoint'");
		$ids = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 0) : [];
		$endpoints = [];

		foreach ($ids as $id) {
			$id = trim((string)$id);
			if ($id !== '') {
				$endpoints[$id] = true;
			}
		}

		return $endpoints;
	}

	private function getEndpointDescriptions(): array {
		$db = $this->db();
		$descriptions = [];

		if ($this->tableExists('users')) {
			$stmt = $db->query("SELECT extension, name FROM users WHERE extension <> ''");
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$descriptions[(string)$row['extension']] = (string)$row['name'];
			}
		}

		if ($this->tableExists('devices')) {
			$stmt = $db->query("SELECT id, description FROM devices WHERE LOWER(tech) = 'pjsip' AND id <> ''");
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$id = (string)$row['id'];
				if (!isset($descriptions[$id]) || $descriptions[$id] === '') {
					$descriptions[$id] = (string)$row['description'];
				}
			}
		}

		return $descriptions;
	}

	private function reconcileCurrentStatus(): void {
		$this->syncDiscoveredEndpoints();

		$contacts = $this->getLiveContacts();
		$registrarDetails = $this->getRegistrarContactDetails();
		$now = $this->now();
		$db = $this->db();
		$endpoints = $this->getStoredEndpoints();

		foreach ($endpoints as $endpoint) {
			if ((int)$endpoint['discovered'] === 0) {
				continue;
			}

			$previousStatus = $this->normaliseState($endpoint['last_known_status'] ?? self::STATUS_UNKNOWN);
			$previousContactUri = $endpoint['contact_uri'] ?? null;
			$previousHadContact = $this->hadRegisteredState($previousStatus) || (string)($endpoint['contact_uri'] ?? '') !== '';
			$contact = $contacts[$endpoint['extension']] ?? null;
			$status = self::STATUS_NOT_REGISTERED;
			$contactUri = null;
			$latency = null;
			$sourceIp = $endpoint['source_ip'] ?? null;
			$sourcePort = $endpoint['source_port'] ?? null;
			$userAgent = $endpoint['user_agent'] ?? null;
			$deviceName = $endpoint['device_name'] ?? null;
			$firmwareVersion = $endpoint['firmware_version'] ?? null;
			$contactExpiresAt = $endpoint['contact_expires_at'] ?? null;
			$qualifyFrequency = $endpoint['qualify_frequency'] ?? null;
			$lastSeen = $endpoint['last_seen_at'] ?: null;

			if ($contact !== null) {
				$status = $contact['status'];
				$contactUri = $contact['contact_uri'];
				$latency = $contact['latency_ms'];
				$sourceIp = $contact['source_ip'] ?? null;
				if ($contactUri !== '') {
					$lastSeen = $now;
				}
			}

			$registrar = $registrarDetails[(string)$endpoint['extension']] ?? [];
			if ($registrar) {
				$contactUri = $registrar['contact_uri'] ?? $contactUri;
				$sourceIp = $registrar['source_ip'] ?? $sourceIp;
				$sourcePort = $registrar['source_port'] ?? $sourcePort;
				$userAgent = $registrar['user_agent'] ?? $userAgent;
				$deviceName = $registrar['device_name'] ?? $deviceName;
				$firmwareVersion = $registrar['firmware_version'] ?? $firmwareVersion;
				$contactExpiresAt = $registrar['contact_expires_at'] ?? $contactExpiresAt;
				$qualifyFrequency = $registrar['qualify_frequency'] ?? $qualifyFrequency;
			}

			if ($previousStatus !== $status) {
				$historyContactUri = $contactUri;
				if ($status === self::STATUS_NOT_REGISTERED && trim((string)$previousContactUri) !== '') {
					$historyContactUri = $previousContactUri;
				}

				$this->insertStatusHistory(
					(string)$endpoint['extension'],
					$previousStatus,
					$status,
					$this->historyReason($previousHadContact, $status),
					$historyContactUri,
					$latency,
					$now
				);
			}

			$stmt = $db->prepare(
				'UPDATE endpointmonitor_endpoints
				SET last_known_status = :last_known_status,
					contact_uri = :contact_uri,
					latency_ms = :latency_ms,
					source_ip = :source_ip,
					source_port = :source_port,
					user_agent = :user_agent,
					device_name = :device_name,
					firmware_version = :firmware_version,
					contact_expires_at = :contact_expires_at,
					qualify_frequency = :qualify_frequency,
					last_seen_at = :last_seen_at,
					last_checked_at = :last_checked_at,
					updated_at = :updated_at
				WHERE extension = :extension'
			);
			$stmt->execute([
				':last_known_status' => $status,
				':contact_uri' => $contactUri,
				':latency_ms' => $latency,
				':source_ip' => $sourceIp,
				':source_port' => $sourcePort,
				':user_agent' => $userAgent,
				':device_name' => $deviceName,
				':firmware_version' => $firmwareVersion,
				':contact_expires_at' => $contactExpiresAt,
				':qualify_frequency' => $qualifyFrequency,
				':last_seen_at' => $lastSeen,
				':last_checked_at' => $now,
				':updated_at' => $now,
				':extension' => $endpoint['extension'],
			]);
		}

		$this->processAlertQueue($now);
	}

	private function getLiveContacts(): array {
		$output = $this->runAsteriskCommand('pjsip show contacts');
		if (trim($output) === '') {
			throw new \Exception(_('No response from Asterisk PJSIP contact query.'));
		}

		$contacts = [];
		$lineCount = 0;
		$parsedCount = 0;
		$failureCount = 0;
		$firstFailedLine = null;

		foreach (preg_split('/\r\n|\r|\n/', $output) as $line) {
			if (trim($line) === '') {
				continue;
			}

			$lineCount++;
			$parsed = $this->parseContactLine($line);
			if ($parsed === null) {
				// Track first failed parse for diagnostic logging
				if (strpos($line, 'Contact:') !== false) {
					$failureCount++;
					if ($firstFailedLine === null) {
						$firstFailedLine = substr($line, 0, 100);
					}
				}
				continue;
			}

			$parsedCount++;
			$existing = $contacts[$parsed['extension']] ?? null;
			if ($existing === null || $this->statusRank($parsed['status']) > $this->statusRank($existing['status'])) {
				$contacts[$parsed['extension']] = $parsed;
			}
		}

		// Log diagnostics only if there were parse failures (avoid log spam on success)
		if ($lineCount > 0 && $failureCount > 0) {
			$this->logWarning('PJSIP contact parsing: ' . $parsedCount . ' parsed, ' . $failureCount . ' failed. Example: ' . $firstFailedLine);
		}

		return $contacts;
	}

	/**
	 * Phase 2 intentionally parses the human-readable PJSIP contact listing
	 * because it is widely available through FreePBX/Asterisk manager command
	 * support. Future work should prefer structured AMI/PJSIP data where that
	 * is practical; keep this parser treated as technical debt.
	 */
	private function parseContactLine(string $line): ?array {
		if (!preg_match('/^\s*Contact:\s+(\S+)\s+(.+)$/', $line, $matches)) {
			return null;
		}

		$target = $matches[1];
		if (strpos($target, '/') === false) {
			return null;
		}

		[$extension, $contactUri] = explode('/', $target, 2);
		$extension = trim($extension);
		$contactUri = trim($contactUri);
		if ($extension === '') {
			return null;
		}

		$tail = preg_split('/\s+/', trim($matches[2]));
		$rawStatus = '';
		$latency = null;

		foreach ($tail as $token) {
			if ($this->isAsteriskStatus($token)) {
				$rawStatus = $token;
			} elseif (is_numeric($token)) {
				$latency = (float)$token;
			}
		}

		$contactAddress = $this->parseContactUriAddress($contactUri);
		$contactHost = $contactAddress['host'];
		$sourceIp = $contactHost !== null && filter_var($contactHost, FILTER_VALIDATE_IP) ? $contactHost : null;

		return [
			'extension' => $extension,
			'contact_uri' => $contactUri,
			'status' => $this->mapAsteriskStatus($rawStatus),
			'latency_ms' => $latency,
			'source_ip' => $sourceIp,
			'transport' => null,  // Not available from "pjsip show contacts"; requires future AMI/log ingestion
			'user_agent' => null, // Not available from "pjsip show contacts"; requires future AMI/log ingestion
		];
	}

	private function parseContactUriAddress(?string $contactUri): array {
		$result = ['host' => null, 'port' => null];
		$contactUri = trim((string)$contactUri);
		if ($contactUri === '') {
			return $result;
		}
		if (preg_match('/\s/', $contactUri)) {
			return $result;
		}

		$contactUri = trim($contactUri, '<>');
		$atPosition = strrpos($contactUri, '@');
		$hostPort = $atPosition === false ? $contactUri : substr($contactUri, $atPosition + 1);
		$hostPort = preg_split('/[;?\#>\s]/', $hostPort, 2)[0] ?? '';
		$hostPort = trim($hostPort);
		if ($hostPort === '') {
			return $result;
		}

		if (strpos($hostPort, ':') !== false && stripos($hostPort, 'sip:') === 0) {
			$hostPort = substr($hostPort, strpos($hostPort, ':') + 1);
		}

		$host = null;
		$port = null;
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2] ?? null;
		} elseif (substr_count($hostPort, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2];
		} elseif (substr_count($hostPort, ':') === 0) {
			$host = $hostPort;
		} else {
			$host = $hostPort;
		}

		$host = trim((string)$host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return $result;
		}
		if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
			return $result;
		}

		$result['host'] = $host;
		if ($port !== null && ctype_digit((string)$port)) {
			$portNumber = (int)$port;
			if ($portNumber > 0 && $portNumber <= 65535) {
				$result['port'] = $portNumber;
			}
		}

		return $result;
	}

	private function parseContactUriOriginalHostAddress(?string $contactUri): array {
		$result = ['host' => null, 'port' => null];
		$contactUri = trim((string)$contactUri);
		if ($contactUri === '' || preg_match('/\s/', $contactUri)) {
			return $result;
		}

		if (!preg_match('/[;?&]x-ast-orig-host=([^;?&#>\s]+)/', $contactUri, $matches)) {
			return $result;
		}

		return $this->parseAddressHostPort(rawurldecode($matches[1]));
	}

	private function endpointAddressDetails(?string $contactUri, $sourceIp = null, $sourcePort = null): array {
		$contactAddress = $this->parseContactUriAddress($contactUri);
		$originalAddress = $this->parseContactUriOriginalHostAddress($contactUri);
		$hasOriginalAddress = $originalAddress['host'] !== null || $originalAddress['port'] !== null;

		$deviceAddress = $hasOriginalAddress ? $originalAddress : $contactAddress;
		$networkAddress = $hasOriginalAddress ? $contactAddress : ['host' => null, 'port' => null];

		if ($networkAddress['host'] === null && trim((string)$sourceIp) !== '') {
			$networkAddress['host'] = $sourceIp;
		}
		if ($networkAddress['port'] === null && trim((string)$sourcePort) !== '') {
			$networkAddress['port'] = $sourcePort;
		}

		return [
			'device_ip' => $deviceAddress['host'],
			'device_port' => $deviceAddress['port'],
			'network_ip' => $networkAddress['host'],
			'network_port' => $networkAddress['port'],
		];
	}

	private function parseAddressHostPort(string $hostPort): array {
		$result = ['host' => null, 'port' => null];
		$hostPort = trim($hostPort, " \t\n\r\0\x0B<>");
		if ($hostPort === '') {
			return $result;
		}

		$host = null;
		$port = null;
		if (preg_match('/^\[([^\]]+)\](?::([0-9]+))?$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2] ?? null;
		} elseif (substr_count($hostPort, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/', $hostPort, $matches)) {
			$host = trim($matches[1]);
			$port = $matches[2];
		} elseif (substr_count($hostPort, ':') === 0) {
			$host = $hostPort;
		} else {
			$host = $hostPort;
		}

		$host = trim((string)$host, " \t\n\r\0\x0B[]");
		if ($host === '') {
			return $result;
		}
		if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
			return $result;
		}

		$result['host'] = $host;
		if ($port !== null && ctype_digit((string)$port)) {
			$portNumber = (int)$port;
			if ($portNumber > 0 && $portNumber <= 65535) {
				$result['port'] = $portNumber;
			}
		}

		return $result;
	}

	private function isAsteriskStatus(string $value): bool {
		return in_array(strtolower($value), ['avail', 'reachable', 'unavail', 'unavailable', 'unreachable', 'unknown', 'removed', 'nonqual'], true);
	}

	private function mapAsteriskStatus(string $value): string {
		switch (strtolower($value)) {
			case 'avail':
			case 'reachable':
				return self::STATUS_REACHABLE;
			case 'unavail':
			case 'unavailable':
			case 'unreachable':
				return self::STATUS_UNREACHABLE;
			case 'removed':
				return self::STATUS_NOT_REGISTERED;
			case 'nonqual':
				return self::STATUS_REGISTERED_NO_QUALIFY;
			case 'unknown':
			default:
				return self::STATUS_UNKNOWN;
		}
	}

	private function statusRank(string $status): int {
		$status = $this->normaliseState($status);

		switch ($status) {
			case self::STATUS_REACHABLE:
				return 5;
			case self::STATUS_UNREACHABLE:
				return 4;
			case self::STATUS_REGISTERED_NO_QUALIFY:
				return 3;
			case self::STATUS_UNKNOWN:
				return 2;
			case self::STATUS_NOT_REGISTERED:
				return 1;
			default:
				return 0;
		}
	}

	private function hadRegisteredState(string $status): bool {
		return in_array($this->normaliseState($status), [
			self::STATUS_REACHABLE,
			self::STATUS_UNREACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
		], true);
	}

	private function historyReason(bool $previousHadContact, string $newStatus): string {
		if ($this->normaliseState($newStatus) === self::STATUS_NOT_REGISTERED && $previousHadContact) {
			return 'removed';
		}

		return 'status_change';
	}

	private function classifyNetworkGroup(?string $sourceIp): string {
		if ($sourceIp === null || $sourceIp === '') {
			return 'Unknown';
		}

		// Check against configured VPN networks
		$vpnNetworks = $this->getTrustedVpnNetworks();
		foreach ($vpnNetworks as $cidr) {
			if ($this->ipInCidr($sourceIp, $cidr)) {
				return "VPN: $cidr";
			}
		}

		// Classify as private/local vs public/WAN
		if ($this->isPrivateIp($sourceIp)) {
			return 'Local LAN';
		}

		return 'WAN';
	}

	private function getTrustedVpnNetworks(): array {
		$settings = $this->getAlertSettings();
		$vpnConfigRaw = $settings['trusted_vpn_networks'] ?? '';
		if ($vpnConfigRaw === '') {
			return [];
		}

		$networks = [];
		foreach (preg_split('/\r\n|\r|\n/', $vpnConfigRaw) as $line) {
			$cidr = trim($line);
			if ($cidr !== '') {
				$networks[] = $cidr;
			}
		}

		return $networks;
	}

	private function ipInCidr(string $ip, string $cidr): bool {
		// Parse CIDR notation (e.g., "10.8.0.0/24")
		if (strpos($cidr, '/') === false) {
			// Invalid CIDR; treat as single IP comparison
			return $ip === $cidr;
		}

		[$network, $bits] = explode('/', $cidr, 2);
		$bits = (int)$bits;

		// Handle IPv4
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$ip = ip2long($ip);
			$network = ip2long($network);
			$mask = -1 << (32 - $bits);
			$network &= $mask;
			return ($ip & $mask) === $network;
		}

		// Handle IPv6 - simplified comparison
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			// For IPv6, do a string prefix match on the network portion
			// This is a simplified check; full IPv6 CIDR support would require inet_pton
			$networkBytes = (int)($bits / 4);
			return strncmp($ip, $network, $networkBytes) === 0;
		}

		return false;
	}

	private function isPrivateIp(string $ip): bool {
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
			&& filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
	}

	public function getTopologyGroups(): array {
		$endpoints = $this->getStoredEndpoints();
		$groups = [
			'VPN' => [],
			'WAN' => [],
			'Local LAN' => [],
			'Unknown' => [],
		];

		foreach ($endpoints as $endpoint) {
			if ((int)$endpoint['discovered'] === 0) {
				continue;
			}

			$sourceIp = $endpoint['source_ip'] ?? null;
			$group = $this->classifyNetworkGroup($sourceIp);

			// Check if group is a VPN network group
			if (strpos($group, 'VPN:') === 0) {
				if (!isset($groups[$group])) {
					$groups[$group] = [];
				}
				$groups[$group][] = $endpoint;
			} else {
				$groups[$group][] = $endpoint;
			}
		}

		// Remove empty groups
		$groups = array_filter($groups, function ($items) {
			return count($items) > 0;
		});

		return $groups;
	}

	private function insertStatusHistory(string $extension, ?string $fromState, string $toState, string $reason, ?string $contactUri, ?float $latency, string $createdAt): int {
		$stmt = $this->db()->prepare(
			'INSERT INTO endpointmonitor_status_history
				(extension, from_state, to_state, source, reason, contact_uri, latency_ms, created_at)
			VALUES
				(:extension, :from_state, :to_state, :source, :reason, :contact_uri, :latency_ms, :created_at)'
		);
		$stmt->execute([
			':extension' => $extension,
			':from_state' => $fromState !== '' ? $fromState : null,
			':to_state' => $toState,
			':source' => 'reconcile',
			':reason' => $reason,
			':contact_uri' => $contactUri,
			':latency_ms' => $latency,
			':created_at' => $createdAt,
		]);
		return (int)$this->db()->lastInsertId();
	}

	private function processAlertQueue(string $now): void {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);
		if ($settings['alert_enabled'] !== '1' || !$recipients) {
			return;
		}

		try {
			$debounceSeconds = min(self::ALERT_TIMING_MAX_SECONDS, max(0, (int)$settings['debounce_seconds']));
			$cutoff = date('Y-m-d H:i:s', strtotime($now) - $debounceSeconds);
			// Endpoint alerts are allowed only for fresh eligible transitions.
			// The stale window is debounce_seconds plus 300 seconds, so old
			// status-history rows cannot be replayed later after recipient or
			// settings changes, while legitimate debounce delays still work.
			$staleCutoff = date('Y-m-d H:i:s', strtotime($now) - ($debounceSeconds + self::ALERT_STALE_TRANSITION_MAX_SECONDS));
			$stmt = $this->db()->prepare(
				'SELECT h.id, h.extension, h.from_state, h.to_state, h.source, h.reason, h.contact_uri, h.latency_ms, h.created_at
				FROM endpointmonitor_status_history h
				JOIN endpointmonitor_endpoints e
					ON e.extension = h.extension
				WHERE h.source = :source
					AND h.created_at <= :cutoff
					AND h.created_at >= :stale_cutoff
					AND e.enabled = 1
				ORDER BY h.created_at ASC, h.id ASC
				LIMIT 100'
			);
			$stmt->execute([
				':source' => 'reconcile',
				':cutoff' => $cutoff,
				':stale_cutoff' => $staleCutoff,
			]);

			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $transition) {
				$alertType = $this->alertTypeForTransition($transition, $settings);
				if ($alertType === null) {
					continue;
				}

				foreach ($recipients as $recipient) {
					if ($this->hasRecordedAlertForRecipient((int)$transition['id'], $alertType, $recipient)) {
						continue;
					}

					if ($this->isRepeatSuppressed($transition, $alertType, $recipient, $settings, $now)) {
						$this->recordSkippedAlert($transition, 'suppressed', $now, $alertType, $recipient);
						continue;
					}

					$email = $this->buildAlertEmail($transition, $alertType);
					$reserved = $this->reserveAlertHistory([
						'extension' => $transition['extension'],
						'history_id' => (int)$transition['id'],
						'alert_type' => $alertType,
						'status' => $transition['to_state'],
						'recipient' => $recipient,
						'subject' => $email['subject'],
						'message' => $email['message'],
						'sent_at' => $now,
						'result' => 'pending',
						'error' => null,
					]);
					if (!$reserved) {
						continue;
					}

					$result = $this->sendEmail($recipient, $email['subject'], $email['message']);
					if (!$result['status']) {
						$this->logWarning('Alert email send failed for ' . $recipient . ' on extension ' . $transition['extension'] . ': ' . $result['message']);
					}
					$this->updateReservedAlertHistory(
						(int)$transition['id'],
						$alertType,
						$recipient,
						$result['status'] ? 'sent' : 'failed',
						$result['status'] ? null : $result['message'],
						$this->now()
					);
				}
			}
		} catch (\Exception $e) {
			$this->logError('Alert processing failed: ' . $e->getMessage());
		}
	}

	private function alertTypeForTransition(array $transition, array $settings): ?string {
		$from = $this->normaliseState($transition['from_state'] ?? '');
		$to = $this->normaliseState($transition['to_state'] ?? '');
		if ($from === '' || $from === self::STATUS_UNKNOWN) {
			return null;
		}

		if ($to === self::STATUS_UNREACHABLE && $settings['alert_on_unreachable'] === '1' && $this->hadRegisteredState($from)) {
			return 'unreachable';
		}

		if ($to === self::STATUS_NOT_REGISTERED && $settings['alert_on_not_registered'] === '1' && in_array($from, [
			self::STATUS_REACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
			self::STATUS_UNREACHABLE,
		], true)) {
			return 'not_registered';
		}

		if ($settings['alert_on_recovery'] === '1' && in_array($from, [
			self::STATUS_UNREACHABLE,
			self::STATUS_NOT_REGISTERED,
		], true) && in_array($to, [
			self::STATUS_REACHABLE,
			self::STATUS_REGISTERED_NO_QUALIFY,
		], true)) {
			return 'recovery';
		}

		return null;
	}

        private function hasRecordedAlertForRecipient(int $historyId, string $alertType, string $recipient): bool {
                $stmt = $this->db()->prepare(
                        'SELECT COUNT(*)
                        FROM endpointmonitor_alert_history
                        WHERE history_id = :history_id
                                AND alert_type = :alert_type
                                AND recipient = :recipient'
                );
                $stmt->execute([
                        ':history_id' => $historyId,
                        ':alert_type' => $alertType,
                        ':recipient' => $recipient,
                ]);

                return (int)$stmt->fetchColumn() > 0;
        }

	private function isRepeatSuppressed(array $transition, string $alertType, string $recipient, array $settings, string $now): bool {
		$seconds = min(self::ALERT_TIMING_MAX_SECONDS, max(0, (int)$settings['repeat_suppression_seconds']));
		if ($seconds === 0) {
			return false;
		}

		$since = date('Y-m-d H:i:s', strtotime($now) - $seconds);
		$stmt = $this->db()->prepare(
			'SELECT COUNT(*)
			FROM endpointmonitor_alert_history
			WHERE extension = :extension
				AND alert_type = :alert_type
				AND recipient = :recipient
				AND result = :result
				AND sent_at >= :since'
		);
		$stmt->execute([
			':extension' => $transition['extension'],
			':alert_type' => $alertType,
			':recipient' => $recipient,
			':result' => 'sent',
			':since' => $since,
		]);

		return (int)$stmt->fetchColumn() > 0;
	}

	private function recordSkippedAlert(array $transition, string $result, string $now, string $alertType = 'none', string $recipient = ''): void {
		$this->insertAlertHistory([
			'extension' => $transition['extension'],
			'history_id' => (int)$transition['id'],
			'alert_type' => $alertType,
			'status' => $transition['to_state'],
			'recipient' => $recipient,
			'subject' => '',
			'message' => '',
			'sent_at' => $now,
			'result' => $result,
			'error' => null,
		]);
	}

	private function runAsteriskCommand(string $command): string {
		$astman = $this->FreePBX->astman ?? null;
		if (!$astman) {
			throw new \Exception(_('Asterisk manager is not available.'));
		}

		if (method_exists($astman, 'Command')) {
			$result = $astman->Command($command);
			return is_array($result) ? implode("\n", $result) : (string)$result;
		}

		if (method_exists($astman, 'send_request')) {
			$result = $astman->send_request('Command', ['Command' => $command]);
			if (is_array($result)) {
				if (isset($result['data'])) {
					return is_array($result['data']) ? implode("\n", $result['data']) : (string)$result['data'];
				}
				return implode("\n", array_map('strval', $result));
			}
			return (string)$result;
		}

		throw new \Exception(_('Asterisk command support is not available.'));
	}

	private function parseUserAgentDetails(?string $userAgent): array {
		$userAgent = trim((string)$userAgent);

		if ($userAgent === '') {
			return [
				'device_name' => null,
				'firmware_version' => null,
			];
		}

		if (preg_match('/^([^\/]+)\/(.+)$/', $userAgent, $matches)) {
			return [
				'device_name' => trim($matches[1]) ?: null,
				'firmware_version' => trim($matches[2]) ?: null,
			];
		}

		if (preg_match('/^(.+?)\s+([0-9]+(?:\.[0-9A-Za-z_-]+)+)$/', $userAgent, $matches)) {
			return [
				'device_name' => trim($matches[1]) ?: null,
				'firmware_version' => trim($matches[2]) ?: null,
			];
		}

		return [
			'device_name' => $userAgent,
			'firmware_version' => null,
		];
	}

	private function getRegistrarContactDetails(): array {
		$details = [];

		try {
			$output = $this->runAsteriskCommand('database show registrar/contact');
		} catch (\Throwable $e) {
			$this->logWarning('Registrar contact metadata unavailable: ' . $e->getMessage());
			return $details;
		}

		foreach (preg_split('/\r\n|\r|\n/', (string)$output) as $line) {
			$line = trim($line);

			if ($line === '' || strpos($line, '/registrar/contact/') !== 0) {
				continue;
			}

			$parts = explode(': ', $line, 2);
			if (count($parts) !== 2) {
				continue;
			}

			$key = $parts[0];
			$json = $parts[1];

			$payload = json_decode($json, true);
			if (!is_array($payload)) {
				continue;
			}

			$endpoint = (string)($payload['endpoint'] ?? '');
			if ($endpoint === '' && preg_match('#^/registrar/contact/([^;]+)#', $key, $matches)) {
				$endpoint = (string)$matches[1];
			}

			if ($endpoint === '') {
				continue;
			}

			$userAgent = isset($payload['user_agent']) ? (string)$payload['user_agent'] : '';
			$parsed = $this->parseUserAgentDetails($userAgent);

			$expiresAt = null;
			if (!empty($payload['expiration_time']) && ctype_digit((string)$payload['expiration_time'])) {
				$expiresAt = date('Y-m-d H:i:s', (int)$payload['expiration_time']);
			}

			$details[$endpoint] = [
				'contact_uri' => isset($payload['uri']) && $payload['uri'] !== '' ? (string)$payload['uri'] : null,
				'source_ip' => isset($payload['via_addr']) && $payload['via_addr'] !== '' ? (string)$payload['via_addr'] : null,
				'source_port' => isset($payload['via_port']) && is_numeric($payload['via_port']) ? (int)$payload['via_port'] : null,
				'user_agent' => $userAgent !== '' ? $userAgent : null,
				'device_name' => $parsed['device_name'],
				'firmware_version' => $parsed['firmware_version'],
				'contact_expires_at' => $expiresAt,
				'qualify_frequency' => isset($payload['qualify_frequency']) && is_numeric($payload['qualify_frequency'])
					? (int)$payload['qualify_frequency']
					: null,
			];
		}

		return $details;
	}

	private function getStoredEndpoints(): array {
		$stmt = $this->db()->query(
			'SELECT extension, description, notes, notes_updated_at, enabled, discovered, last_known_status, contact_uri,
				source_ip, source_port, transport, user_agent, device_name, firmware_version,
				contact_expires_at, qualify_frequency, last_heartbeat_at, latency_ms, last_seen_at,
				last_checked_at, first_discovered_at, last_discovered_at
			FROM endpointmonitor_endpoints
			ORDER BY extension'
		);

		$rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
		if (!is_array($rows)) {
			return [];
		}

		return array_map([$this, 'withEndpointDisplayAddress'], $rows);
	}

	private function getEndpointMapRows(): array {
		$rows = [];
		foreach ($this->getStoredEndpoints() as $endpoint) {
			if ((int)$endpoint['discovered'] === 0) {
				continue;
			}
			$rows[] = [
				'extension' => $endpoint['extension'],
				'description' => $endpoint['description'],
				'enabled' => (int)$endpoint['enabled'],
				'status' => $endpoint['last_known_status'],
				'contact_uri' => $endpoint['contact_uri'],
				'source_ip' => $endpoint['source_ip'],
				'source_port' => $endpoint['source_port'] ?? null,
				'device_ip' => $endpoint['device_ip'] ?? null,
				'device_port' => $endpoint['device_port'] ?? null,
				'network_ip' => $endpoint['network_ip'] ?? null,
				'network_port' => $endpoint['network_port'] ?? null,
				'seen_by_asterisk' => $endpoint['seen_by_asterisk'] ?? null,
				'user_agent' => $endpoint['user_agent'] ?? null,
				'device_name' => $endpoint['device_name'] ?? null,
				'firmware_version' => $endpoint['firmware_version'] ?? null,
				'contact_expires_at' => $endpoint['contact_expires_at'] ?? null,
				'qualify_frequency' => $endpoint['qualify_frequency'] ?? null,
				'latency_ms' => $endpoint['latency_ms'],
				'last_seen_at' => $endpoint['last_seen_at'],
				'last_checked_at' => $endpoint['last_checked_at'],
			];
		}

		return $rows;
	}

	private function getLastRefreshTime(): string {
		$stmt = $this->db()->query('SELECT MAX(last_checked_at) FROM endpointmonitor_endpoints');
		$value = $stmt ? $stmt->fetchColumn() : '';
		return $value ? (string)$value : '';
	}

	private function getStatusHistory(): array {
		$stmt = $this->db()->query(
			'SELECT id, extension, from_state, to_state, source, reason, contact_uri, latency_ms, created_at
			FROM endpointmonitor_status_history
			ORDER BY created_at DESC, id DESC
			LIMIT 25'
		);

		$rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
		foreach ($rows as &$row) {
			$row['source'] = $this->sourceLabel($row['source'] ?? '');
			$row['reason'] = $this->reasonLabel($row['reason'] ?? '');
		}
		unset($row);

		return $rows;
	}

	private function getAlertHistory(): array {
		$stmt = $this->db()->query(
			'SELECT id, extension, history_id, alert_type, status, recipient, subject, sent_at, result, error
			FROM endpointmonitor_alert_history
			ORDER BY sent_at DESC, id DESC
			LIMIT 25'
		);

		return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
	}

	private function getPruneSettings(): array {
		$settings = $this->getAlertSettings();

		return [
			'status_history_prune_policy' => $this->normalisePrunePolicy($settings['status_history_prune_policy'] ?? 'never'),
			'alert_history_prune_policy' => $this->normalisePrunePolicy($settings['alert_history_prune_policy'] ?? 'never'),
		];
	}

	private function normalisePrunePolicy(string $policy): string {
		$policy = strtolower(trim($policy));
		return in_array($policy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true) ? $policy : 'never';
	}

	private function applyHistoryPruning(?string $historyType = null, ?string $policy = null): array {
		$deleted = [
			'status' => 0,
			'alert' => 0,
		];

		if ($historyType !== null) {
			if ($historyType === 'status') {
				$deleted['status'] = $this->pruneStatusHistory($this->normalisePrunePolicy((string)$policy));
			} elseif ($historyType === 'alert') {
				$deleted['alert'] = $this->pruneAlertHistory($this->normalisePrunePolicy((string)$policy));
			}

			return $deleted;
		}

		$settings = $this->getPruneSettings();
		$deleted['status'] = $this->pruneStatusHistory($settings['status_history_prune_policy']);
		$deleted['alert'] = $this->pruneAlertHistory($settings['alert_history_prune_policy']);

		return $deleted;
	}

	private function pruneStatusHistory(string $policy): int {
		$cutoff = $this->pruneCutoff($policy);
		if ($cutoff === null) {
			return 0;
		}

		$stmt = $this->db()->prepare('DELETE FROM endpointmonitor_status_history WHERE created_at < :cutoff');
		$stmt->execute([':cutoff' => $cutoff]);
		return $stmt->rowCount();
	}

	private function pruneAlertHistory(string $policy): int {
		$cutoff = $this->pruneCutoff($policy);
		if ($cutoff === null) {
			return 0;
		}

		$stmt = $this->db()->prepare('DELETE FROM endpointmonitor_alert_history WHERE sent_at < :cutoff');
		$stmt->execute([':cutoff' => $cutoff]);
		return $stmt->rowCount();
	}

	private function pruneCutoff(string $policy): ?string {
		$policy = $this->normalisePrunePolicy($policy);
		if ($policy === 'never') {
			return null;
		}

		try {
			$cutoff = new \DateTimeImmutable($this->now());
			switch ($policy) {
				case 'hourly':
					$cutoff = $cutoff->modify('-1 hour');
					break;
				case 'daily':
					$cutoff = $cutoff->modify('-1 day');
					break;
				case 'monthly':
					$cutoff = $cutoff->modify('-1 month');
					break;
				case 'yearly':
					$cutoff = $cutoff->modify('-1 year');
					break;
				default:
					return null;
			}
		} catch (\Exception $e) {
			$this->logError('Unable to calculate history pruning cutoff: ' . $e->getMessage());
			return null;
		}

		return $cutoff->format('Y-m-d H:i:s');
	}

	private function normaliseAlertTimingSeconds(string $key, string $default): ?int {
		$value = isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : trim($default);
		if ($value === '' || !ctype_digit($value)) {
			return null;
		}

		$seconds = (int)$value;
		if ($seconds < 0 || $seconds > self::ALERT_TIMING_MAX_SECONDS) {
			return null;
		}

		return $seconds;
	}

	private function positiveRequestId(string $key): int {
		$value = isset($_REQUEST[$key]) ? (string)$_REQUEST[$key] : '';
		if (!ctype_digit($value)) {
			return 0;
		}

		$id = (int)$value;
		return $id > 0 ? $id : 0;
	}

	private function getAlertSettings(): array {
		$settings = $this->settingsDefaults;
		$stmt = $this->db()->query('SELECT setting_key, setting_value FROM endpointmonitor_settings');
		if ($stmt) {
			foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
				$key = (string)$row['setting_key'];
				if (array_key_exists($key, $settings)) {
					$settings[$key] = (string)$row['setting_value'];
				}
			}
		}

		return $settings;
	}

	private function setSetting(string $key, string $value): void {
		$stmt = $this->db()->prepare(
			'INSERT INTO endpointmonitor_settings (setting_key, setting_value, updated_at)
			VALUES (:setting_key, :setting_value, :updated_at)
			ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
		);
		$stmt->execute([
			':setting_key' => $key,
			':setting_value' => $value,
			':updated_at' => $this->now(),
		]);
	}

	private function normaliseRecipients(string $raw): array {
		$parts = preg_split('/[,;\s]+/', trim($raw));
		$recipients = [];

		foreach ($parts as $part) {
			$email = trim((string)$part);
			if ($email === '') {
				continue;
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$recipients[strtolower($email)] = $email;
		}

		return array_values($recipients);
	}

	private function getEndpointDetails(string $extension): array {
		$stmt = $this->db()->prepare(
			'SELECT extension, description, last_known_status, contact_uri, source_ip, source_port,
				user_agent, device_name, firmware_version, contact_expires_at,
				qualify_frequency, last_checked_at
			FROM endpointmonitor_endpoints
			WHERE extension = :extension
			LIMIT 1'
		);
		$stmt->execute([':extension' => $extension]);

		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return is_array($row) ? $this->withEndpointDisplayAddress($row) : [];
	}

	private function withEndpointDisplayAddress(array $endpoint): array {
		$addressDetails = $this->endpointAddressDetails(
			$endpoint['contact_uri'] ?? null,
			$endpoint['source_ip'] ?? null,
			$endpoint['source_port'] ?? null
		);
		$endpoint['device_ip'] = $addressDetails['device_ip'];
		$endpoint['device_port'] = $addressDetails['device_port'];
		$endpoint['network_ip'] = $addressDetails['network_ip'];
		$endpoint['network_port'] = $addressDetails['network_port'];
		$endpoint['seen_by_asterisk'] = $this->formatSeenByAsterisk(
			$endpoint['source_ip'] ?? null,
			$endpoint['source_port'] ?? null
		);

		return $endpoint;
	}

	private function formatSeenByAsterisk($sourceIp, $sourcePort): ?string {
		$sourceIp = trim((string)$sourceIp);
		if ($sourceIp === '') {
			return null;
		}

		if ($sourcePort !== null && $sourcePort !== '' && is_numeric($sourcePort)) {
			$port = (int)$sourcePort;
			if ($port > 0 && $port <= 65535) {
				return $sourceIp . ':' . $port;
			}
		}

		return $sourceIp;
	}

	private function buildAlertEmail(array $transition, string $alertType): array {
		$extension = (string)$transition['extension'];
		$toState = $this->normaliseState($transition['to_state'] ?? '');
		$subjectStatus = $this->stateLabel($toState);
		if ($alertType === 'recovery') {
			$subjectStatus = 'has recovered';
		}

		$subject = 'EndPoint Monitor: ' . $extension . ' ' . ($alertType === 'recovery' ? $subjectStatus : 'is ' . $subjectStatus);
		$latency = $transition['latency_ms'] !== null && $transition['latency_ms'] !== '' ? $transition['latency_ms'] . ' ms' : 'Unavailable';
		if ($toState === self::STATUS_REGISTERED_NO_QUALIFY) {
			$latency = 'Unavailable; qualify is not enabled.';
		}
		$endpointDetails = $this->getEndpointDetails($extension);
		$deviceName = trim((string)($endpointDetails['device_name'] ?? ''));
		$firmwareVersion = trim((string)($endpointDetails['firmware_version'] ?? ''));
		$userAgent = trim((string)($endpointDetails['user_agent'] ?? ''));
		$historicalAddress = $toState === self::STATUS_NOT_REGISTERED;
		$contactUriForAddress = $endpointDetails['contact_uri'] ?? null;
		$sourceIpForAddress = $endpointDetails['source_ip'] ?? null;
		$sourcePortForAddress = $endpointDetails['source_port'] ?? null;
		if ($toState === self::STATUS_NOT_REGISTERED) {
			$contactUriForAddress = $transition['contact_uri'] ?? null;
		}
		$addressDetails = $this->endpointAddressDetails($contactUriForAddress, $sourceIpForAddress, $sourcePortForAddress);
		$addressPrefix = $historicalAddress ? 'Last ' : '';
		$message = [
			'EndPoint Monitor state change',
			'',
			'Extension: ' . $extension,
			'New state: ' . $this->stateLabel($toState),
			'Reason: ' . $this->reasonLabel($transition['reason'] ?? ''),
			'Latency: ' . $latency,
			'',
			'Device: ' . ($deviceName !== '' ? $deviceName : 'Unknown'),
			'Version: ' . ($firmwareVersion !== '' ? $firmwareVersion : 'Unknown'),
			$addressPrefix . 'Device IP: ' . (($addressDetails['device_ip'] ?? '') !== '' ? $addressDetails['device_ip'] : 'Unknown'),
			$addressPrefix . 'Device Port: ' . (($addressDetails['device_port'] ?? '') !== '' ? $addressDetails['device_port'] : 'Unknown'),
			$addressPrefix . 'Network IP: ' . (($addressDetails['network_ip'] ?? '') !== '' ? $addressDetails['network_ip'] : 'Unknown'),
			$addressPrefix . 'Network Port: ' . (($addressDetails['network_port'] ?? '') !== '' ? $addressDetails['network_port'] : 'Unknown'),
			'Contact expires: ' . (($endpointDetails['contact_expires_at'] ?? '') !== '' ? $endpointDetails['contact_expires_at'] : 'Unknown'),
			'Qualify frequency: ' . (($endpointDetails['qualify_frequency'] ?? '') !== '' ? $endpointDetails['qualify_frequency'] . ' seconds' : 'Unknown'),
			'Transition time: ' . $transition['created_at'],
			'Source: Asterisk',
			'',
			'Please note: email deliveries can be delayed.',
			'Check current status in the FreePBX module.',
		];

		return [
			'subject' => $subject,
			'message' => implode("\n", $message),
		];
	}

	private function sendEmail(string $recipient, string $subject, string $message): array {
		// Follow the broad FreePBX missedcall CI_Email pattern. Do not fall
		// back to raw mail(); failures are returned for alert-history storage.
		try {
			if (!class_exists('\CI_Email')) {
				return ['status' => false, 'message' => 'CI_Email is not available.'];
			}

			$from = $this->getNotificationFromAddress();
			if ($from === '') {
				return ['status' => false, 'message' => 'Email "From:" Address is not configured in Advanced Settings.'];
			}
			$senderName = $this->getNotificationSenderName();

			$email = new \CI_Email();
			if ($this->emailFromSupportsReturnPath($email)) {
				$email->from($from, $senderName, $from);
			} else {
				$email->from($from, $senderName);
				if (method_exists($email, 'set_header')) {
					$email->set_header('Return-Path', $from);
				}
			}
			if (method_exists($email, 'reply_to')) {
				$email->reply_to($from, $senderName);
			}
			$email->to($recipient);
			$email->subject($subject);
			$email->set_mailtype('text');
			$email->message($message);

			if ($email->send()) {
				return ['status' => true, 'message' => 'accepted by local mailer; delivery not confirmed'];
			}

			$error = 'CI_Email send failed.';
			if (method_exists($email, 'print_debugger')) {
				$debug = trim(strip_tags((string)$email->print_debugger(['headers'])));
				if ($debug !== '') {
					$error .= ' ' . $debug;
				}
			}

			return ['status' => false, 'message' => $error];
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}
	}

	private function getNotificationFromAddress(): string {
		if (method_exists($this, 'fetchFromEmail')) {
			$email = $this->normaliseEmailAddress((string)$this->fetchFromEmail());
			if ($email !== '') {
				return $email;
			}
		}

		try {
			$email = $this->normaliseEmailAddress((string)\FreePBX::Config()->get('AMPUSERMANEMAILFROM'));
			if ($email !== '') {
				return $email;
			}
		} catch (\Exception $e) {
			// Fall through and fail safely rather than guessing a sender domain.
		}

		return '';
	}

	private function normaliseEmailAddress(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/<([^>]+)>/', $value, $matches)) {
			$value = trim($matches[1]);
		}

		return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
	}

	private function getNotificationSenderName(): string {
		try {
			$brand = (string)\FreePBX::Config()->get('DASHBOARD_FREEPBX_BRAND');
			if ($brand !== '') {
				return $brand;
			}
		} catch (\Exception $e) {
			// Keep EndPoint Monitor as the sender name fallback.
		}

		return 'EndPoint Monitor';
	}

	private function emailFromSupportsReturnPath($email): bool {
		try {
			$method = new \ReflectionMethod($email, 'from');
			return $method->getNumberOfParameters() >= 3;
		} catch (\ReflectionException $e) {
			return false;
		}
	}

	private function insertAlertHistory(array $alert): void {
		$stmt = $this->db()->prepare(
			'INSERT IGNORE INTO endpointmonitor_alert_history
				(extension, history_id, alert_type, status, recipient, subject, message, sent_at, result, error)
			VALUES
				(:extension, :history_id, :alert_type, :status, :recipient, :subject, :message, :sent_at, :result, :error)'
		);
		$stmt->execute([
			':extension' => $alert['extension'],
			':history_id' => $alert['history_id'],
			':alert_type' => $alert['alert_type'],
			':status' => $alert['status'],
			':recipient' => $alert['recipient'],
			':subject' => $alert['subject'],
			':message' => $alert['message'],
			':sent_at' => $alert['sent_at'],
			':result' => $alert['result'],
			':error' => $alert['error'],
		]);
	}

	private function reserveAlertHistory(array $alert): bool {
		$stmt = $this->db()->prepare(
			'INSERT IGNORE INTO endpointmonitor_alert_history
				(extension, history_id, alert_type, status, recipient, subject, message, sent_at, result, error)
			VALUES
				(:extension, :history_id, :alert_type, :status, :recipient, :subject, :message, :sent_at, :result, :error)'
		);
		$stmt->execute([
			':extension' => $alert['extension'],
			':history_id' => $alert['history_id'],
			':alert_type' => $alert['alert_type'],
			':status' => $alert['status'],
			':recipient' => $alert['recipient'],
			':subject' => $alert['subject'],
			':message' => $alert['message'],
			':sent_at' => $alert['sent_at'],
			':result' => $alert['result'],
			':error' => $alert['error'],
		]);

		return $stmt->rowCount() > 0;
	}

	private function updateReservedAlertHistory(int $historyId, string $alertType, string $recipient, string $result, ?string $error, string $sentAt): void {
		$stmt = $this->db()->prepare(
			'UPDATE endpointmonitor_alert_history
			SET sent_at = :sent_at,
				result = :result,
				error = :error
			WHERE history_id = :history_id
				AND alert_type = :alert_type
				AND recipient = :recipient'
		);
		$stmt->execute([
			':sent_at' => $sentAt,
			':result' => $result,
			':error' => $error,
			':history_id' => $historyId,
			':alert_type' => $alertType,
			':recipient' => $recipient,
		]);
	}

	private function tableExists(string $table): bool {
		$stmt = $this->db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
		$stmt->execute([':table' => $table]);
		return (int)$stmt->fetchColumn() > 0;
	}

	private function createCsrfToken(): string {
		if (!$this->ensureSessionForCsrfWrite()) {
			return '';
		}

		$token = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		if ($token !== '') {
			return $token;
		}

		try {
			$token = bin2hex(random_bytes(32));
			$_SESSION[self::CSRF_SESSION_KEY] = $token;
			return $token;
		} catch (\Throwable $e) {
			$this->logWarning('Unable to create EndPoint Monitor CSRF token: ' . $e->getMessage());
			return '';
		}
	}

	private function ensureSessionForCsrfWrite(): bool {
		if (!function_exists('session_status')) {
			return isset($_SESSION) && is_array($_SESSION);
		}

		$status = session_status();
		if ($status === PHP_SESSION_ACTIVE) {
			return true;
		}

		if ($status === PHP_SESSION_DISABLED || headers_sent()) {
			return false;
		}

		return @session_start();
	}

	private function validateCsrfToken(): bool {
		$sessionToken = isset($_SESSION[self::CSRF_SESSION_KEY]) ? (string)$_SESSION[self::CSRF_SESSION_KEY] : '';
		if ($sessionToken === '') {
			return false;
		}

		$token = isset($_REQUEST['token']) ? (string)$_REQUEST['token'] : '';
		if ($token === '') {
			return false;
		}

		return hash_equals($sessionToken, $token);
	}

	private function logError(string $message): void {
		try {
			if (method_exists('\FreePBX', 'Log')) {
				\FreePBX::Log()->error('endpointmonitor: ' . $message);
			}
		} catch (\Exception $e) {
			// Logging unavailable; silently continue
		}
	}

	private function logWarning(string $message): void {
		try {
			if (method_exists('\FreePBX', 'Log')) {
				\FreePBX::Log()->warning('endpointmonitor: ' . $message);
			}
		} catch (\Exception $e) {
			// Logging unavailable; silently continue
		}
	}


	public function runBackgroundMonitor($output = null): bool {
		try {
			$settings = $this->getAlertSettings();
			$this->applyHistoryPruning();

			$interval = (int)($settings['topology_poll_interval_seconds'] ?? 10);
			if ($interval <= 0) {
				if ($output && method_exists($output, 'writeln')) {
					$output->writeln('EndPoint Monitor background job skipped: polling disabled.');
				}
				return true;
			}

			$this->reconcileCurrentStatus();

			if ($output && method_exists($output, 'writeln')) {
				if (($settings['alert_enabled'] ?? '0') === '1') {
					$output->writeln('EndPoint Monitor background job completed.');
				} else {
					$output->writeln('EndPoint Monitor background job completed; alerts disabled.');
				}
			}

			return true;
		} catch (\Throwable $e) {
			$this->logError('Background job failed: ' . $e->getMessage());

			if ($output && method_exists($output, 'writeln')) {
				$output->writeln('<error>EndPoint Monitor background job failed: ' . $e->getMessage() . '</error>');
			}

			return false;
		}
	}

	private function getEmailStatus(): array {
		$settings = $this->getAlertSettings();
		$recipients = $this->normaliseRecipients($settings['alert_recipients']);

		return [
			'ci_email_available' => class_exists('\CI_Email'),
			'alerts_enabled' => $settings['alert_enabled'] === '1',
			'recipients_configured' => count($recipients) > 0,
			'recipient_count' => count($recipients),
		];
	}

	private function db() {
		return \FreePBX::Database();
	}

	private function sourceLabel(?string $source): string {
		$source = trim((string)$source);

		if ($source === 'reconcile') {
			return 'Asterisk';
		}

		return $source !== '' ? $source : '-';
	}

	private function reasonLabel(?string $reason): string {
		$reason = trim((string)$reason);

		switch ($reason) {
			case 'status_change':
				return 'Status changed';
			case 'removed':
				return 'Contact removed';
		}

		return $reason !== '' ? $reason : '-';
	}

	private function stateLabel(?string $state): string {
		$state = $this->normaliseState($state);

		return $state !== '' ? $state : '-';
	}

	private function normaliseState($state): string {
		$state = trim((string)$state);

		switch (strtolower($state)) {
			case 'not_registered':
			case 'not registered':
				return self::STATUS_NOT_REGISTERED;
			case 'registered_no_qualify':
			case 'registered (no qualify)':
				return self::STATUS_REGISTERED_NO_QUALIFY;
		}

		return $state;
	}

	private function now(): string {
		return date('Y-m-d H:i:s');
	}
}
