<?php
/**
 * EndPoint Monitor main view.
 *
 * @var string $moduleVersion
 * @var array $endpoints
 * @var array $statusHistory
 * @var array $alertSettings
 * @var array $pruneSettings
 * @var array $alertHistory
 * @var string $lastRefresh
 * @var string $refreshError
 * @var array $emailStatus
 * @var int $pollIntervalSeconds
 * @var string $csrfToken
 */
if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$endpoints = isset($endpoints) && is_array($endpoints) ? $endpoints : [];
$statusHistory = isset($statusHistory) && is_array($statusHistory) ? $statusHistory : [];
$alertSettings = isset($alertSettings) && is_array($alertSettings) ? $alertSettings : [];
$pruneSettings = isset($pruneSettings) && is_array($pruneSettings) ? $pruneSettings : [];
$alertHistory = isset($alertHistory) && is_array($alertHistory) ? $alertHistory : [];
$mapEndpoints = array_values(array_filter($endpoints, function ($endpoint) {
	return isset($endpoint['discovered']) && (int)$endpoint['discovered'] === 1;
}));
$uiShowLimit = isset($alertSettings['ui_show_limit']) ? strtolower((string)$alertSettings['ui_show_limit']) : '6';
if (!in_array($uiShowLimit, ['6', '30', '60', '120', 'all'], true)) {
	$uiShowLimit = '6';
}
$statusPrunePolicy = isset($pruneSettings['status_history_prune_policy']) ? strtolower((string)$pruneSettings['status_history_prune_policy']) : 'never';
$alertPrunePolicy = isset($pruneSettings['alert_history_prune_policy']) ? strtolower((string)$pruneSettings['alert_history_prune_policy']) : 'never';
if (!in_array($statusPrunePolicy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true)) {
	$statusPrunePolicy = 'never';
}
if (!in_array($alertPrunePolicy, ['hourly', 'daily', 'monthly', 'yearly', 'never'], true)) {
	$alertPrunePolicy = 'never';
}
$mapDefaultLimit = $uiShowLimit === 'all' ? count($mapEndpoints) : (int)$uiShowLimit;
$mapVisibleEndpoints = $uiShowLimit === 'all' ? $mapEndpoints : array_slice($mapEndpoints, 0, $mapDefaultLimit);
$mapEndpointTotal = count($mapEndpoints);
$mapVisibleCount = count($mapVisibleEndpoints);
$lastRefresh = isset($lastRefresh) ? (string)$lastRefresh : '';
$refreshError = isset($refreshError) ? (string)$refreshError : '';
$emailStatus = isset($emailStatus) && is_array($emailStatus) ? $emailStatus : [
	'ci_email_available' => false,
	'alerts_enabled' => false,
	'recipients_configured' => false,
	'recipient_count' => 0,
];
$pollIntervalSeconds = isset($pollIntervalSeconds) ? (int)$pollIntervalSeconds : 10;
$csrfToken = isset($csrfToken) ? (string)$csrfToken : '';

$_emStatusClass = function ($status) {
	switch ((string)$status) {
		case 'Reachable':
			return 'em-led-green';
		case 'Registered (no qualify)':
			return 'em-led-amber';
		case 'Unreachable':
			return 'em-led-red';
		case 'Not registered':
			return 'em-led-grey';
		case 'Unknown':
		default:
			return 'em-led-amber';
	}
};

$_emDisplayLabel = function ($value) {
	$value = trim((string)$value);
	if ($value === '') {
		return '-';
	}

	$labels = [
		'recovery' => 'Recovery',
		'unreachable' => 'Unreachable',
		'not_registered' => 'Not registered',
		'not registered' => 'Not registered',
		'registered_no_qualify' => 'Registered (no qualify)',
		'registered (no qualify)' => 'Registered (no qualify)',
		'status_change' => 'Status changed',
		'status changed' => 'Status changed',
		'removed' => 'Contact removed',
		'contact removed' => 'Contact removed',
		'sent' => 'Sent',
		'failed' => 'Failed',
		'suppressed' => 'Suppressed',
		'pending' => 'Pending',
		'test' => 'Test',
	];

	$key = strtolower($value);
	if (isset($labels[$key])) {
		return $labels[$key];
	}

	return ucwords(str_replace('_', ' ', $value));
};

$_emContactExpiryText = function ($expiresAt) {
	$expiresAt = trim((string)$expiresAt);
	if ($expiresAt === '') {
		return _('Unknown');
	}

	$expiryTimestamp = strtotime($expiresAt);
	if ($expiryTimestamp === false) {
		return _('Unknown');
	}

	$remainingSeconds = $expiryTimestamp - time();
	if ($remainingSeconds < 0) {
		return _('Expired');
	}

	return (string)$remainingSeconds . 's';
};

$_emAssetVer = max(
	@filemtime(__DIR__ . '/../assets/js/endpointmonitor.js') ?: 0,
	@filemtime(__DIR__ . '/../assets/css/endpointmonitor.css') ?: 0
) ?: time();
?>
<link rel="stylesheet" href="modules/endpointmonitor/assets/css/endpointmonitor.css?v=<?php echo $_emAssetVer; ?>">

<div class="endpointmonitor" data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>" data-poll-interval="<?php echo (int)$pollIntervalSeconds; ?>">
	<input type="hidden" name="token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
	<div class="row">
		<div class="col-sm-12">
			<h1>
				<?php echo _('EndPoint Monitor'); ?>
				<small class="text-muted" style="font-size:0.5em;">v<?php echo htmlspecialchars($moduleVersion, ENT_QUOTES, 'UTF-8'); ?></small>
			</h1>

			<div id="em-message" class="alert em-message" style="<?php echo $refreshError === '' ? 'display:none;' : ''; ?>">
				<?php echo htmlspecialchars($refreshError, ENT_QUOTES, 'UTF-8'); ?>
			</div>
		</div>
	</div>

	<div class="row em-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('EndPoint Status Map'); ?></h3>
				</div>
					<div class="panel-body">
						<p style="margin-bottom: 15px;">
							<strong><?php echo _('Auto Refresh Interval'); ?>:</strong>
							<?php if ($pollIntervalSeconds <= 0): ?>
								<span class="label label-default"><?php echo _('Disabled'); ?></span>
							<?php else: ?>
								<span class="label label-info"><?php echo $pollIntervalSeconds . ' ' . _('seconds'); ?></span>
							<?php endif; ?>
						</p>
						<div class="form-inline" style="margin-bottom: 15px;">
							<label for="em-map-limit" style="margin-right: 6px;"><?php echo _('Show'); ?></label>
							<select id="em-map-limit" class="form-control input-sm em-shared-show-limit" data-section="map" style="width:auto;">
								<option value="6" <?php echo $uiShowLimit === '6' ? 'selected' : ''; ?>>6</option>
								<option value="30" <?php echo $uiShowLimit === '30' ? 'selected' : ''; ?>>30</option>
								<option value="60" <?php echo $uiShowLimit === '60' ? 'selected' : ''; ?>>60</option>
								<option value="120" <?php echo $uiShowLimit === '120' ? 'selected' : ''; ?>>120</option>
								<option value="all" <?php echo $uiShowLimit === 'all' ? 'selected' : ''; ?>><?php echo _('All'); ?></option>
							</select>
							<span id="em-map-count" class="text-muted" style="margin-left: 10px;">
								<?php echo sprintf(_('Showing %d of %d EndPoints'), $mapVisibleCount, $mapEndpointTotal); ?>
							</span>
						</div>
					<div id="em-topology-container" style="min-height: 200px;">
						<?php if (empty($mapEndpoints)): ?>
							<p class="text-muted"><?php echo _('No EndPoints discovered yet. Use Manual Refresh to discover EndPoints.'); ?></p>
						<?php else: ?>
							<div class="em-endpoint-map">
								<?php foreach ($mapVisibleEndpoints as $endpoint): ?>
									<div class="em-map-tile">
										<div class="em-map-title">
											<span class="em-led <?php echo $_emStatusClass($endpoint['last_known_status']); ?>"></span>
											<span><?php echo htmlspecialchars($endpoint['extension'], ENT_QUOTES, 'UTF-8'); ?></span>
										</div>
										<div class="em-map-description"><?php echo htmlspecialchars($endpoint['description'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-status"><?php echo htmlspecialchars($_emDisplayLabel($endpoint['last_known_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Device IP'); ?>: <?php echo htmlspecialchars(($endpoint['device_ip'] ?? '') !== '' ? (string)$endpoint['device_ip'] : _('Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Device Port'); ?>: <?php echo htmlspecialchars(($endpoint['device_port'] ?? '') !== '' ? (string)$endpoint['device_port'] : _('Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Network IP'); ?>: <?php echo htmlspecialchars(($endpoint['network_ip'] ?? '') !== '' ? (string)$endpoint['network_ip'] : _('Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Network Port'); ?>: <?php echo htmlspecialchars(($endpoint['network_port'] ?? '') !== '' ? (string)$endpoint['network_port'] : _('Unknown'), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Device'); ?>: <?php echo htmlspecialchars(($endpoint['device_name'] ?? '') !== '' ? (string)$endpoint['device_name'] : '-', ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Version'); ?>: <?php echo htmlspecialchars(($endpoint['firmware_version'] ?? '') !== '' ? (string)$endpoint['firmware_version'] : '-', ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Contact expires'); ?>: <?php echo htmlspecialchars($_emContactExpiryText($endpoint['contact_expires_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail"><?php echo _('Qualify'); ?>: <?php echo htmlspecialchars(($endpoint['qualify_frequency'] ?? '') !== '' ? (string)$endpoint['qualify_frequency'] . ' seconds' : '-', ENT_QUOTES, 'UTF-8'); ?></div>
										<div class="em-map-detail">
											<?php echo _('Latency'); ?>:
											<?php if ($endpoint['latency_ms'] !== null && $endpoint['latency_ms'] !== ''): ?>
												<?php echo htmlspecialchars((string)$endpoint['latency_ms'], ENT_QUOTES, 'UTF-8'); ?> ms
											<?php elseif ($endpoint['last_known_status'] === 'Registered (no qualify)'): ?>
												<?php echo _('Unavailable; qualify is not enabled.'); ?>
											<?php else: ?>
												-
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row em-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('Monitored EndPoints'); ?></h3>
				</div>
				<div class="panel-body">
					<?php if (empty($endpoints)): ?>
						<p class="em-placeholder"><?php echo _('No PJSIP EndPoints are stored yet. Use Manual Refresh to discover EndPoints.'); ?></p>
					<?php else: ?>
						<div class="table-responsive em-endpoints-wrap">
							<table class="table table-striped table-condensed em-endpoints">
								<thead>
									<tr>
										<th><?php echo _('Selection'); ?></th>
										<th><?php echo _('EndPoint'); ?></th>
										<th><?php echo _('Description'); ?></th>
										<th><?php echo _('Notes'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($endpoints as $endpoint): ?>
										<tr data-extension="<?php echo htmlspecialchars($endpoint['extension'], ENT_QUOTES, 'UTF-8'); ?>">
											<td data-label="<?php echo _('Selection'); ?>">
													<label class="em-toggle">
														<input type="checkbox" class="em-enabled" <?php echo !empty($endpoint['enabled']) ? 'checked' : ''; ?>>
														<span><?php echo !empty($endpoint['enabled']) ? _('Selected') : _('Not selected'); ?></span>
													</label>
											</td>
											<td data-label="<?php echo _('EndPoint'); ?>"><?php echo htmlspecialchars($endpoint['extension'], ENT_QUOTES, 'UTF-8'); ?></td>
											<td data-label="<?php echo _('Description'); ?>"><?php echo htmlspecialchars($endpoint['description'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
											<td data-label="<?php echo _('Notes'); ?>">
											<input
												type="text"
												class="form-control input-sm em-endpoint-notes"
												data-extension="<?php echo htmlspecialchars((string)$endpoint['extension'], ENT_QUOTES, 'UTF-8'); ?>"
												maxlength="48"
												value="<?php echo htmlspecialchars((string)($endpoint['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
												placeholder="<?php echo _('Add note...'); ?>"
											>
											<small class="text-muted em-notes-status">
												<?php if (!empty($endpoint['notes_updated_at'])): ?>
													<?php echo _('Saved'); ?> <?php echo htmlspecialchars((string)$endpoint['notes_updated_at'], ENT_QUOTES, 'UTF-8'); ?>
												<?php endif; ?>
											</small>
										</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<div class="row em-section">
		<div class="col-sm-12">
			<div class="panel panel-default">
				<div class="panel-heading">
					<h3 class="panel-title"><?php echo _('Alert Settings'); ?></h3>
				</div>
				<div class="panel-body">
					<div class="row">
						<div class="col-sm-6">
							<div class="checkbox">
								<label>
									<input type="checkbox" id="em-alert-enabled" <?php echo ($alertSettings['alert_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Enable email alerts'); ?>
								</label>
							</div>
							<div class="form-group">
								<label for="em-alert-recipients"><?php echo _('Recipients'); ?></label>
								<input type="text" id="em-alert-recipients" class="form-control" value="<?php echo htmlspecialchars($alertSettings['alert_recipients'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo _('admin@example.com, support@example.com'); ?>">
							</div>
							<div class="form-group">
								<label for="em-debounce-seconds"><?php echo _('Debounce delay (seconds)'); ?></label>
								<input type="number" id="em-debounce-seconds" class="form-control" min="0" max="86400" step="1" value="<?php echo htmlspecialchars($alertSettings['debounce_seconds'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
								<p class="help-block"><?php echo _('How long a problem must remain active before an alert is sent. Use 0 to alert immediately. Maximum 86400 seconds, 24 hours.'); ?></p>
							</div>
							<div class="form-group">
								<label for="em-repeat-suppression-seconds"><?php echo _('Repeat suppression (seconds)'); ?></label>
								<input type="number" id="em-repeat-suppression-seconds" class="form-control" min="0" max="86400" step="1" value="<?php echo htmlspecialchars($alertSettings['repeat_suppression_seconds'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
								<p class="help-block"><?php echo _('How long to wait before sending another alert for the same EndPoint and alert type. Use 0 to send every eligible alert. Maximum 86400 seconds, 24 hours.'); ?></p>
							</div>
						</div>
						<div class="col-sm-6">
							<div class="checkbox">
								<label>
									<input type="checkbox" id="em-alert-on-unreachable" <?php echo ($alertSettings['alert_on_unreachable'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when an EndPoint becomes unreachable'); ?>
								</label>
							</div>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="em-alert-on-not-registered" <?php echo ($alertSettings['alert_on_not_registered'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when an EndPoint becomes not registered'); ?>
								</label>
							</div>
							<div class="checkbox">
								<label>
									<input type="checkbox" id="em-alert-on-recovery" <?php echo ($alertSettings['alert_on_recovery'] ?? '1') === '1' ? 'checked' : ''; ?>>
									<?php echo _('Alert when an EndPoint recovers'); ?>
								</label>
							</div>
							<div class="em-actions">
								<button type="button" id="em-save-alerts" class="btn btn-primary">
									<i class="fa fa-save"></i> <?php echo _('Save'); ?>
								</button>
								<button type="button" id="em-test-email" class="btn btn-default">
									<i class="fa fa-envelope"></i> <?php echo _('Test Email'); ?>
								</button>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row em-section">
		<div class="col-sm-12">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php echo _('Status History'); ?></h3>
					</div>
					<div class="panel-body">
						<div class="em-history-control-row">
							<div class="em-prune-control" data-history-type="status" data-active-policy="<?php echo htmlspecialchars($statusPrunePolicy, ENT_QUOTES, 'UTF-8'); ?>">
								<div class="form-inline em-prune-inline">
									<label for="em-status-prune-policy"><?php echo _('Prune'); ?></label>
									<select id="em-status-prune-policy" class="form-control input-sm em-prune-policy">
										<option value="never" <?php echo $statusPrunePolicy === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
										<option value="hourly" <?php echo $statusPrunePolicy === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
										<option value="daily" <?php echo $statusPrunePolicy === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
										<option value="monthly" <?php echo $statusPrunePolicy === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
										<option value="yearly" <?php echo $statusPrunePolicy === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
									</select>
									<button type="button" class="btn btn-default btn-sm em-apply-prune"><?php echo _('Apply'); ?></button>
								</div>
								<label class="checkbox-inline em-prune-confirm-wrap" style="display:none;">
									<input type="checkbox" class="em-prune-confirm">
									<?php echo _('I understand this will permanently delete older Status History rows.'); ?>
								</label>
							</div>
							<div class="em-history-show-slot" data-show-section="status-history"></div>
						</div>
						<?php if (empty($statusHistory)): ?>
							<p class="em-placeholder em-history-empty"><?php echo _('No status transitions have been recorded yet.'); ?></p>
						<?php else: ?>
							<p class="em-placeholder em-history-empty" style="display:none;"><?php echo _('No status transitions have been recorded yet.'); ?></p>
						<?php endif; ?>
					<div class="table-responsive em-history-wrap" style="<?php echo empty($statusHistory) ? 'display:none;' : ''; ?>">
						<table class="table table-striped table-condensed em-history">
							<thead>
								<tr>
									<th><?php echo _('Time'); ?></th>
									<th><?php echo _('EndPoint'); ?></th>
									<th><?php echo _('From'); ?></th>
									<th><?php echo _('To'); ?></th>
									<th><?php echo _('Source'); ?></th>
									<th><?php echo _('Reason'); ?></th>
									<th><?php echo _('Latency'); ?></th>
									<th><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($statusHistory as $entry): ?>
									<tr data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>">
										<td data-label="<?php echo _('Time'); ?>"><?php echo htmlspecialchars($entry['created_at'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('EndPoint'); ?>"><?php echo htmlspecialchars($entry['extension'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('From'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['from_state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('To'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['to_state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Source'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['source'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Reason'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Latency'); ?>"><?php echo $entry['latency_ms'] !== null && $entry['latency_ms'] !== '' ? htmlspecialchars((string)$entry['latency_ms'], ENT_QUOTES, 'UTF-8') . ' ms' : '-'; ?></td>
										<td data-label="<?php echo _('Actions'); ?>">
											<button type="button" class="btn btn-xs btn-danger em-delete-status-history" data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" title="<?php echo _('Delete Status History row'); ?>">
												<i class="fa fa-trash"></i>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row em-section">
		<div class="col-sm-12">
				<div class="panel panel-default">
					<div class="panel-heading">
						<h3 class="panel-title"><?php echo _('Alert History'); ?></h3>
					</div>
					<div class="panel-body">
						<div class="em-history-control-row">
							<div class="em-prune-control" data-history-type="alert" data-active-policy="<?php echo htmlspecialchars($alertPrunePolicy, ENT_QUOTES, 'UTF-8'); ?>">
								<div class="form-inline em-prune-inline">
									<label for="em-alert-prune-policy"><?php echo _('Prune'); ?></label>
									<select id="em-alert-prune-policy" class="form-control input-sm em-prune-policy">
										<option value="never" <?php echo $alertPrunePolicy === 'never' ? 'selected' : ''; ?>><?php echo _('Never'); ?></option>
										<option value="hourly" <?php echo $alertPrunePolicy === 'hourly' ? 'selected' : ''; ?>><?php echo _('Hourly'); ?></option>
										<option value="daily" <?php echo $alertPrunePolicy === 'daily' ? 'selected' : ''; ?>><?php echo _('Daily'); ?></option>
										<option value="monthly" <?php echo $alertPrunePolicy === 'monthly' ? 'selected' : ''; ?>><?php echo _('Monthly'); ?></option>
										<option value="yearly" <?php echo $alertPrunePolicy === 'yearly' ? 'selected' : ''; ?>><?php echo _('Yearly'); ?></option>
									</select>
									<button type="button" class="btn btn-default btn-sm em-apply-prune"><?php echo _('Apply'); ?></button>
								</div>
								<label class="checkbox-inline em-prune-confirm-wrap" style="display:none;">
									<input type="checkbox" class="em-prune-confirm">
									<?php echo _('I understand this will permanently delete older Alert History rows.'); ?>
								</label>
							</div>
							<div class="em-history-show-slot" data-show-section="alert-history"></div>
						</div>
						<?php if (empty($alertHistory)): ?>
							<p class="em-placeholder em-alert-history-empty"><?php echo _('No alert attempts have been recorded yet.'); ?></p>
						<?php else: ?>
							<p class="em-placeholder em-alert-history-empty" style="display:none;"><?php echo _('No alert attempts have been recorded yet.'); ?></p>
						<?php endif; ?>
					<div class="table-responsive em-alert-history-wrap" style="<?php echo empty($alertHistory) ? 'display:none;' : ''; ?>">
						<table class="table table-striped table-condensed em-alert-history">
							<thead>
								<tr>
									<th><?php echo _('Time'); ?></th>
									<th><?php echo _('EndPoint'); ?></th>
									<th><?php echo _('Type'); ?></th>
									<th><?php echo _('Status'); ?></th>
									<th><?php echo _('Recipient'); ?></th>
									<th><?php echo _('Result'); ?></th>
									<th><?php echo _('Error'); ?></th>
									<th><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($alertHistory as $entry): ?>
									<tr data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>">
										<td data-label="<?php echo _('Time'); ?>"><?php echo htmlspecialchars($entry['sent_at'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('EndPoint'); ?>"><?php echo htmlspecialchars($entry['extension'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Type'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['alert_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Status'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Recipient'); ?>"><?php echo htmlspecialchars($entry['recipient'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Result'); ?>"><?php echo htmlspecialchars($_emDisplayLabel($entry['result'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Error'); ?>"><?php echo htmlspecialchars($entry['error'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
										<td data-label="<?php echo _('Actions'); ?>">
											<button type="button" class="btn btn-xs btn-danger em-delete-alert-history" data-history-id="<?php echo (int)($entry['id'] ?? 0); ?>" title="<?php echo _('Delete Alert History row'); ?>">
												<i class="fa fa-trash"></i>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<script src="modules/endpointmonitor/assets/js/endpointmonitor.js?v=<?php echo $_emAssetVer; ?>"></script>
<script>
	// Endpoint map renderer. Auto-refresh uses the read-only topology AJAX path in endpointmonitor.js.
	(function() {
		const textNoEndpoints = <?php echo json_encode(_('No EndPoints discovered yet. Use Manual Refresh to discover EndPoints.')); ?>;
		const textDeviceIp = <?php echo json_encode(_('Device IP')); ?>;
		const textDevicePort = <?php echo json_encode(_('Device Port')); ?>;
		const textNetworkIp = <?php echo json_encode(_('Network IP')); ?>;
		const textNetworkPort = <?php echo json_encode(_('Network Port')); ?>;
		const textLatency = <?php echo json_encode(_('Latency')); ?>;
		const textNoQualify = <?php echo json_encode(_('Unavailable; qualify is not enabled.')); ?>;
		const textUnknown = <?php echo json_encode(_('Unknown')); ?>;
		const textExpired = <?php echo json_encode(_('Expired')); ?>;
		let latestMapEndpoints = <?php echo json_encode($mapEndpoints); ?>;

		function statusClass(status) {
			switch (status || 'Unknown') {
				case 'Reachable':
					return 'em-led-green';
				case 'Registered (no qualify)':
					return 'em-led-amber';
				case 'Unreachable':
					return 'em-led-red';
				case 'Not registered':
					return 'em-led-grey';
				case 'Unknown':
				default:
					return 'em-led-amber';
			}
		}

		function latencyText(endpoint, status) {
			if (endpoint.latency_ms !== null && endpoint.latency_ms !== undefined && endpoint.latency_ms !== '') {
				return escapeHtml(endpoint.latency_ms) + ' ms';
			}
			if (status === 'Registered (no qualify)') {
				return textNoQualify;
			}
			return '-';
		}

		function displayLabel(value) {
			const text = String(value || '').trim();
			const labels = {
				recovery: 'Recovery',
				unreachable: 'Unreachable',
				not_registered: 'Not registered',
				'not registered': 'Not registered',
				registered_no_qualify: 'Registered (no qualify)',
				'registered (no qualify)': 'Registered (no qualify)',
				status_change: 'Status changed',
				'status changed': 'Status changed',
				removed: 'Contact removed',
				'contact removed': 'Contact removed',
				sent: 'Sent',
				failed: 'Failed',
				suppressed: 'Suppressed',
				pending: 'Pending',
				test: 'Test'
			};

			if (!text) {
				return '-';
			}

			return labels[text.toLowerCase()] || text;
		}

		function contactExpiryText(expiresAt) {
			if (!expiresAt) {
				return textUnknown;
			}

			const expiryTime = Date.parse(String(expiresAt).replace(' ', 'T'));
			if (Number.isNaN(expiryTime)) {
				return textUnknown;
			}

			const remainingSeconds = Math.floor((expiryTime - Date.now()) / 1000);
			if (remainingSeconds < 0) {
				return textExpired;
			}

			return remainingSeconds + 's';
		}

		function discoveredEndpoints(endpoints) {
			if (!Array.isArray(endpoints)) {
				return [];
			}

			return endpoints.filter(function(endpoint) {
				return endpoint.discovered === undefined || parseInt(endpoint.discovered, 10) !== 0;
			});
		}

		function selectedMapLimit() {
			const select = document.getElementById('em-map-limit');
			if (!select || select.value === 'all') {
				return 'all';
			}

			const value = parseInt(select.value, 10);
			return value > 0 ? value : 6;
		}

		function updateMapCount(shown, total) {
			const count = document.getElementById('em-map-count');
			if (!count) {
				return;
			}

			count.textContent = 'Showing ' + shown + ' of ' + total + ' EndPoints';
		}

		function renderEndpointMap(endpoints) {
			const container = document.getElementById('em-topology-container');
			if (!container) return;

			latestMapEndpoints = Array.isArray(endpoints) ? endpoints : [];

			const discovered = discoveredEndpoints(latestMapEndpoints);
			const total = discovered.length;
			const limit = selectedMapLimit();
			const visible = limit === 'all' ? discovered : discovered.slice(0, limit);

			updateMapCount(visible.length, total);

			if (total === 0) {
				container.innerHTML = '<p class="text-muted">' + escapeHtml(textNoEndpoints) + '</p>';
				return;
			}

			let html = '<div class="em-endpoint-map">';
			for (const endpoint of visible) {
				const status = endpoint.status || endpoint.last_known_status || 'Unknown';
				html += '<div class="em-map-tile">';
				html += '<div class="em-map-title"><span class="em-led ' + statusClass(status) + '"></span><span>' + escapeHtml(endpoint.extension) + '</span></div>';
				html += '<div class="em-map-description">' + escapeHtml(endpoint.description || '-') + '</div>';
				html += '<div class="em-map-status">' + escapeHtml(displayLabel(status)) + '</div>';
				html += '<div class="em-map-detail">' + escapeHtml(textDeviceIp) + ': ' + escapeHtml(endpoint.device_ip || textUnknown) + '</div>';
				html += '<div class="em-map-detail">' + escapeHtml(textDevicePort) + ': ' + escapeHtml(endpoint.device_port || textUnknown) + '</div>';
				html += '<div class="em-map-detail">' + escapeHtml(textNetworkIp) + ': ' + escapeHtml(endpoint.network_ip || textUnknown) + '</div>';
				html += '<div class="em-map-detail">' + escapeHtml(textNetworkPort) + ': ' + escapeHtml(endpoint.network_port || textUnknown) + '</div>';
				html += '<div class="em-map-detail">Device: ' + escapeHtml(endpoint.device_name || '-') + '</div>';
				html += '<div class="em-map-detail">Version: ' + escapeHtml(endpoint.firmware_version || '-') + '</div>';
				html += '<div class="em-map-detail">Contact expires: ' + escapeHtml(contactExpiryText(endpoint.contact_expires_at)) + '</div>';
				html += '<div class="em-map-detail">Qualify: ' + escapeHtml(endpoint.qualify_frequency ? endpoint.qualify_frequency + ' seconds' : '-') + '</div>';
				html += '<div class="em-map-detail">' + escapeHtml(textLatency) + ': ' + latencyText(endpoint, status) + '</div>';
				html += '</div>';
			}
			html += '</div>';

			container.innerHTML = html;
		}
		window.EndpointMonitorRenderEndpointMap = renderEndpointMap;

		const mapLimitSelect = document.getElementById('em-map-limit');
		if (mapLimitSelect) {
			mapLimitSelect.addEventListener('change', function() {
				renderEndpointMap(latestMapEndpoints);
			});
		}

		function escapeHtml(text) {
			if (!text) return '';
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	})();
</script>
