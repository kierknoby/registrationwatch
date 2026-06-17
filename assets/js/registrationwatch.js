/**
 * Registration Watch admin interactions.
 */
(function ($) {
	'use strict';

	function escapeHtml(value) {
		return $('<div>').text(value === null || value === undefined || value === '' ? '-' : String(value)).html();
	}

	function escapeHtmlValue(value) {
		return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
	}

	function displayLabel(value) {
		var text = $.trim(String(value || ''));
		var labels = {
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
			reminder: 'Repeat alert',
			'repeat alert': 'Repeat alert',
			storm_summary: 'Storm Summary',
			'storm summary': 'Storm Summary',
			storm_suppressed: 'Storm suppressed',
			storm_summary_failed: 'Storm summary failed',
			sent: 'Sent',
			failed: 'Failed',
			pending: 'Pending',
			suppressed: 'Suppressed',
			test: 'Test'
		};

		if (!text) {
			return '-';
		}

		var key = text.toLowerCase();
		if (labels[key]) {
			return labels[key];
		}

		return text.replace(/_/g, ' ').replace(/\b\w/g, function (match) {
			return match.toUpperCase();
		});
	}

	function isRegisteredNoQualify(value) {
		value = $.trim(String(value || '')).toLowerCase();
		return value === 'registered (no qualify)' || value === 'registered_no_qualify';
	}

	function showMessage(message, level) {
		if (!message) {
			return;
		}
		if (window.RegistrationWatchToast) {
			window.RegistrationWatchToast(message, level === 'error' ? 'danger' : (level || 'success'));
			return;
		}
		if (level === 'error') {
			var el = $('#rw-message');
			el.removeClass('alert-success alert-danger alert-info').addClass('alert-danger');
			el.text(message).show();
		}
	}

	function normaliseToken(value) {
		value = $.trim(String(value || ''));
		if (value.charAt(0) !== '<') {
			return value;
		}

		var input = $(value).filter('input').add($(value).find('input')).filter('[name="token"], [name="csrf_token"], [name="freepbx_token"]').first();
		return input.length ? $.trim(String(input.val() || '')) : value;
	}

	function registrationWatchToken(root) {
		var scope = root && root.length ? root : $('.registrationwatch');
		return normaliseToken(
			scope.attr('data-csrf-token')
				|| scope.data('csrf-token')
				|| $('input[name="token"]').first().val()
				|| $('input[name="csrf_token"]').first().val()
				|| $('input[name="freepbx_token"]').first().val()
				|| ''
		);
	}

	window.RegistrationWatchToken = registrationWatchToken;

	function registrationRows(registrationId) {
		return $('.registrationwatch tr[data-registration-id]').filter(function () {
			return String($(this).data('registration-id')) === String(registrationId);
		});
	}

	function renderStatusRows(registrations) {
		$.each(registrations || [], function (_, registration) {
			var rows = registrationRows(registration.registration_id || registration.id);
			var status = registration.last_known_status || registration.status || '-';
			rows.find('.rw-status-value').text(displayLabel(status));
			rows.find('.rw-device-name').text(registration.device_name || '-');
			rows.find('.rw-firmware-version').text(registration.firmware_version || '-');
			rows.find('.rw-source-ip').text(registration.source_ip || '-');
			rows.find('.rw-source-port').text(registration.source_port || '-');
			rows.find('.rw-contact-uri').html(escapeHtml(registration.contact_uri));
			rows.find('.rw-last-seen').text(registration.last_seen_at || '-');
			rows.find('.rw-last-checked').text(registration.last_checked_at || '-');
			if (registration.latency_ms) {
				rows.find('.rw-latency').text(registration.latency_ms + ' ms');
			} else if (isRegisteredNoQualify(status)) {
				rows.find('.rw-latency').text('Unavailable; qualify is not enabled.');
			} else {
				rows.find('.rw-latency').text('-');
			}
		});
	}

	function watchedExtensionsPanel() {
		var found = $();
		$('.registrationwatch .panel-title').each(function () {
			if ($.trim($(this).text()) === 'Watched Extensions') {
				found = $(this).closest('.panel');
				return false;
			}
		});

		return found;
	}

	function repeatModeOptionsHtml(selectedMode) {
		var options = [
			['', 'Use global'],
			['never', 'Never'],
			['5m', 'Every 5 minutes'],
			['hourly', 'Hourly'],
			['daily', 'Daily'],
			['escalating', 'Escalating']
		];
		var selected = String(selectedMode || '');
		if (selected === 'fibonacci') {
			selected = 'escalating';
		}
		var html = '';

		$.each(options, function (_, option) {
			html += '<option value="' + escapeHtml(option[0]) + '"' + (selected === option[0] ? ' selected' : '') + '>' + escapeHtml(option[1]) + '</option>';
		});

		return html;
	}

	function watchedExtensionsTableHtml() {
		return '<div class="table-responsive rw-registrations-wrap">' +
			'<table class="table table-striped table-condensed rw-registrations rw-watched-registrations-table">' +
				'<colgroup>' +
					'<col class="rw-col-selection">' +
					'<col class="rw-col-extension">' +
					'<col class="rw-col-description">' +
					'<col class="rw-col-repeat">' +
					'<col class="rw-col-notes">' +
				'</colgroup>' +
				'<thead>' +
					'<tr>' +
						'<th>Monitored</th>' +
						'<th>Extension</th>' +
						'<th>Description</th>' +
						'<th>Repeat alerts</th>' +
						'<th>Notes</th>' +
					'</tr>' +
				'</thead>' +
				'<tbody></tbody>' +
			'</table>' +
		'</div>';
	}

	function watchedExtensionsEmptyText() {
		var pollInterval = parseInt($('.registrationwatch').attr('data-poll-interval'), 10) || 0;
		if (pollInterval > 0) {
			return 'No watched extensions discovered yet. Registration Watch checks automatically every ' + pollInterval + ' seconds; extensions will appear here once discovered.';
		}

		return 'No watched extensions discovered yet. Registration Watch will show extensions here once automatic checks run.';
	}

	function isActivelyAlerting(registration) {
		if (!parseInt(registration.enabled, 10)) {
			return false;
		}
		var status = String(registration.last_known_status || registration.status || '').trim().toLowerCase();
		return status === 'unreachable' || status === 'not registered';
	}

	function rowSnoozeSelectHtml() {
		return '<div class="btn-group rw-row-snooze-group">'
			+ '<button type="button" class="btn btn-xs btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'
			+ '💤 Snooze <span class="caret"></span>'
			+ '</button>'
			+ '<ul class="dropdown-menu">'
			+ '<li><a href="#" class="rw-row-snooze-option" data-seconds="300">Snooze 5m</a></li>'
			+ '<li><a href="#" class="rw-row-snooze-option" data-seconds="900">Snooze 15m</a></li>'
			+ '<li><a href="#" class="rw-row-snooze-option" data-seconds="1800">Snooze 30m</a></li>'
			+ '<li><a href="#" class="rw-row-snooze-option" data-seconds="3600">Snooze 1h</a></li>'
			+ '<li><a href="#" class="rw-row-snooze-option" data-seconds="86400">Snooze 1d</a></li>'
			+ '</ul>'
			+ '</div>';
	}

	function rowSnoozeInactiveHtml() {
		return '<span class="rw-row-snooze-inactive" title="Global monitoring snooze is active">💤 Snoozed</span>';
	}

	function rowSnoozeControlHtml() {
		return (currentMonitoringState && currentMonitoringState.state === 'snoozed')
			? rowSnoozeInactiveHtml()
			: rowSnoozeSelectHtml();
	}

	function buildMonitoredCellHtml(enabled) {
		var toggleHtml = '<label class="rw-toggle">'
			+ '<input type="checkbox" class="rw-enabled"' + (enabled ? ' checked' : '') + '>'
			+ '<span class="rw-toggle-slider"></span>'
			+ '</label>';
		if (enabled) {
			return '<div class="rw-monitored-cell">' + toggleHtml + rowSnoozeControlHtml() + '</div>';
		}
		return toggleHtml;
	}

	function watchedExtensionRowHtml(registration) {
		var id = parseInt(registration.registration_id || registration.id, 10) || 0;
		var notes = registration.notes || '';
		var notesStatus = registration.notes_updated_at ? 'Saved ' + registration.notes_updated_at : '';
		var monitoredCell;
		if (isActivelyAlerting(registration)) {
			monitoredCell = '<div class="rw-alerting-cell">'
				+ '<small class="rw-alerting-indicator">Actively alerting</small>'
				+ '<button type="button" class="btn btn-xs btn-warning rw-disable-alerting" data-registration-id="' + id + '" title="Disable alerting for this extension">Disable alerting</button>'
				+ rowSnoozeControlHtml()
				+ '</div>';
		} else {
			monitoredCell = buildMonitoredCellHtml(parseInt(registration.enabled, 10));
		}

		return '<tr data-registration-id="' + id + '" data-extension="' + escapeHtml(registration.extension) + '"' + (parseInt(registration.enabled, 10) ? ' class="rw-row-enabled"' : '') + '>' +
			'<td data-label="Monitored">' + monitoredCell + '</td>' +
			'<td data-label="Extension">' + escapeHtml(registration.extension) + '</td>' +
			'<td data-label="Description">' + escapeHtml(registration.description || '-') + '</td>' +
			'<td data-label="Repeat alerts">' +
				'<select class="form-control input-sm rw-repeat-mode" data-registration-id="' + id + '">' +
					repeatModeOptionsHtml(registration.repeat_mode) +
				'</select>' +
				'<small class="text-muted rw-repeat-mode-status"></small>' +
			'</td>' +
			'<td data-label="Notes">' +
				'<input type="text" class="form-control input-sm rw-registration-notes" data-registration-id="' + id + '" maxlength="72" value="' + escapeHtmlValue(notes) + '" placeholder="Add note...">' +
				'<small class="text-muted rw-notes-status">' + escapeHtmlValue(notesStatus) + '</small>' +
			'</td>' +
		'</tr>';
	}

	function renderWatchedExtensionsTable(registrations) {
		var $panel = watchedExtensionsPanel();
		if (!$panel.length) {
			return;
		}

		var rows = [];
		var activeNote = $('.registrationwatch .rw-registration-notes:focus');
		var activeNoteId = activeNote.length ? String(activeNote.data('registration-id') || '') : '';
		var activeNoteValue = activeNote.length ? activeNote.val() : null;
		var activeNoteSelection = activeNote.length && activeNote[0].setSelectionRange ? {
			start: activeNote[0].selectionStart,
			end: activeNote[0].selectionEnd
		} : null;

		$.each(registrations || [], function (_, registration) {
			if (registration.discovered !== undefined && parseInt(registration.discovered, 10) === 0) {
				return;
			}
			rows.push(watchedExtensionRowHtml(registration));
		});

		var $body = $panel.find('.panel-body').first();
		if (!rows.length) {
			$body.html('<p class="rw-placeholder">' + escapeHtmlValue(watchedExtensionsEmptyText()) + '</p>');
			$(document).trigger('registrationwatch:history-rendered');
			return;
		}

		if (!$panel.find('.rw-registrations').length) {
			$body.html(watchedExtensionsTableHtml());
		}

		$panel.find('.rw-registrations tbody').html(rows.join(''));
		$panel.find('.rw-repeat-mode').each(function () {
			$(this).data('previous-value', $(this).val() || '');
		});

		if (activeNoteId) {
			var restored = $panel.find('.rw-registration-notes').filter(function () {
				return String($(this).data('registration-id') || '') === activeNoteId;
			}).first();
			if (restored.length) {
				restored.val(activeNoteValue).focus();
				if (activeNoteSelection && restored[0].setSelectionRange) {
					restored[0].setSelectionRange(activeNoteSelection.start, activeNoteSelection.end);
				}
			}
		}

		$(document).trigger('registrationwatch:history-rendered');
	}

	function historyRowClass(toState) {
		var s = $.trim(String(toState || '')).toLowerCase();
		if (s === 'reachable' || s === 'registered (no qualify)') {
			return 'rw-history-row-ok';
		}
		if (s === 'unreachable' || s === 'not registered') {
			return 'rw-history-row-bad';
		}
		return 'rw-history-row-neutral';
	}

	function renderHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			var latency = entry.latency_ms ? escapeHtml(entry.latency_ms) + ' ms' : '-';
			var id = parseInt(entry.id, 10) || 0;
			rows.push(
				'<tr data-history-id="' + id + '" class="' + historyRowClass(entry.to_state) + '">' +
					'<td data-label="Time">' + escapeHtml(entry.created_at) + '</td>' +
					'<td data-label="Extension">' + escapeHtml(entry.extension) + '</td>' +
					'<td data-label="From">' + escapeHtml(displayLabel(entry.from_state)) + '</td>' +
					'<td data-label="To">' + escapeHtml(displayLabel(entry.to_state)) + '</td>' +
					'<td data-label="Source">' + escapeHtml(displayLabel(entry.source)) + '</td>' +
					'<td data-label="Reason">' + escapeHtml(displayLabel(entry.reason)) + '</td>' +
					'<td data-label="Latency">' + latency + '</td>' +
					'<td data-label="Actions"><button type="button" class="btn btn-xs btn-danger rw-delete-status-history" data-history-id="' + id + '" title="Delete Status History row"><i class="fa fa-trash"></i></button></td>' +
				'</tr>'
			);
		});

		$('.rw-history tbody').html(rows.join(''));
		$('.rw-history-empty').toggle(rows.length === 0);
		$('.rw-history-wrap').toggle(rows.length > 0);
		$(document).trigger('registrationwatch:history-rendered');
	}

	function alertHistoryRowClass(alertType) {
		var s = $.trim(String(alertType || '')).toLowerCase();
		if (s === 'recovery') {
			return 'rw-alert-history-row-ok';
		}
		if (s === 'unreachable' || s === 'not_registered' || s === 'not registered') {
			return 'rw-alert-history-row-bad';
		}
		return 'rw-alert-history-row-neutral';
	}

	function renderAlertHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			var id = parseInt(entry.id, 10) || 0;
			rows.push(
				'<tr data-history-id="' + id + '" class="' + alertHistoryRowClass(entry.alert_type) + '">' +
					'<td data-label="Time">' + escapeHtml(entry.sent_at) + '</td>' +
					'<td data-label="Extension">' + escapeHtml(entry.extension) + '</td>' +
					'<td data-label="Type">' + escapeHtml(displayLabel(entry.alert_type)) + '</td>' +
					'<td data-label="Status">' + escapeHtml(displayLabel(entry.status)) + '</td>' +
					'<td data-label="Recipient">' + escapeHtml(entry.recipient) + '</td>' +
					'<td data-label="Result">' + escapeHtml(displayLabel(entry.result)) + '</td>' +
					'<td data-label="Error">' + escapeHtml(entry.error) + '</td>' +
					'<td data-label="Actions"><button type="button" class="btn btn-xs btn-danger rw-delete-alert-history" data-history-id="' + id + '" title="Delete Alert History row"><i class="fa fa-trash"></i></button></td>' +
				'</tr>'
			);
		});

		$('.rw-alert-history tbody').html(rows.join(''));
		$('.rw-alert-history-empty').toggle(rows.length === 0);
		$('.rw-alert-history-wrap').toggle(rows.length > 0);
		$(document).trigger('registrationwatch:history-rendered');
	}

	function alertSettingsPayload(command, csrfToken) {
		return {
			command: command,
			token: csrfToken,
			alert_enabled: $('#rw-alert-enabled').is(':checked') ? 1 : 0,
			alert_recipients: $('#rw-alert-recipients').val(),
			repeat_mode: $('#rw-repeat-mode').val(),
			storm_threshold: $('#rw-storm-threshold').val(),
			alert_on_unreachable: $('#rw-alert-on-unreachable').is(':checked') ? 1 : 0,
			alert_on_not_registered: $('#rw-alert-on-not-registered').is(':checked') ? 1 : 0,
			alert_on_recovery: $('#rw-alert-on-recovery').is(':checked') ? 1 : 0,
			debounce_seconds: $('#rw-debounce-seconds').val()
		};
	}

	var snoozeCountdownTimer = null;
	var snoozeUntilTs = 0;
	var currentMonitoringState = null;

	function formatCountdown(remainingSeconds) {
		if (remainingSeconds <= 0) {
			return '0 secs remaining';
		}
		var mins = Math.floor(remainingSeconds / 60);
		var secs = remainingSeconds % 60;
		if (mins > 0) {
			return mins + ' min' + (mins === 1 ? '' : 's') + ' ' + secs + ' secs remaining';
		}
		return secs + ' secs remaining';
	}

	function stopSnoozeCountdown() {
		if (snoozeCountdownTimer) {
			clearInterval(snoozeCountdownTimer);
			snoozeCountdownTimer = null;
		}
		snoozeUntilTs = 0;
	}

	function startSnoozeCountdown(untilTs) {
		stopSnoozeCountdown();
		snoozeUntilTs = untilTs;

		function tick() {
			var remaining = Math.max(0, Math.floor((snoozeUntilTs - Date.now()) / 1000));
			var el = document.getElementById('rw-snooze-countdown');
			if (el) {
				el.textContent = formatCountdown(remaining);
			}
			if (remaining <= 0) {
				stopSnoozeCountdown();
				updateMonitoringBanner({state: 'active', snoozed_until: null});
			}
		}

		tick();
		snoozeCountdownTimer = setInterval(tick, 10000);
	}

	function updateMonitoringBanner(monitoringState) {
		currentMonitoringState = monitoringState;

		var banner = document.getElementById('rw-monitoring-banner');
		if (!banner) {
			return;
		}

		stopSnoozeCountdown();

		var state = monitoringState && monitoringState.state ? monitoringState.state : 'active';
		var html = '';

		if (state === 'inactive') {
			banner.className = 'rw-monitoring-banner rw-monitoring-inactive';
			html = '<div class="rw-monitoring-banner-body">'
				+ '<strong>Monitoring is inactive</strong>'
				+ '<p class="rw-monitoring-desc">No watched extensions are currently monitored.</p>'
				+ '</div>';
		} else if (state === 'snoozed') {
			banner.className = 'rw-monitoring-banner rw-monitoring-snoozed';
			html = '<div class="rw-monitoring-banner-body">'
				+ '<strong>Monitoring is snoozed</strong>'
				+ '<p class="rw-monitoring-desc">Alerts are paused. Status checks continue.</p>'
				+ '<p class="rw-monitoring-desc"><span id="rw-snooze-countdown">Calculating...</span></p>'
				+ '<div class="rw-monitoring-actions">'
				+ '<button type="button" class="btn btn-sm btn-default rw-resume-btn">Resume monitoring</button>'
				+ '</div>'
				+ '</div>';
		} else {
			banner.className = 'rw-monitoring-banner rw-monitoring-active';
			html = '<div class="rw-monitoring-banner-body">'
				+ '<strong>Monitoring active</strong>'
				+ '<p class="rw-monitoring-desc">Status checks are running. Alerts will be sent for monitored extensions.</p>'
				+ '<div class="rw-monitoring-actions">'
				+ '<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="300">Snooze 5m</button>'
				+ '<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="900">Snooze 15m</button>'
				+ '<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="1800">Snooze 30m</button>'
				+ '<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="3600">Snooze 1h</button>'
				+ '<button type="button" class="btn btn-sm btn-default rw-snooze-btn" data-seconds="86400">Snooze 1d</button>'
				+ '</div>'
				+ '</div>';
		}

		banner.innerHTML = html;

		if (state === 'snoozed' && monitoringState.snoozed_until) {
			var untilTs = Date.parse(String(monitoringState.snoozed_until).replace(' ', 'T'));
			if (!isNaN(untilTs)) {
				startSnoozeCountdown(untilTs);
			}
		}

		var globalSnoozed = state === 'snoozed';
		var snoozeCtrlHtml = globalSnoozed ? rowSnoozeInactiveHtml() : rowSnoozeSelectHtml();
		$('.registrationwatch .rw-monitored-cell, .registrationwatch .rw-alerting-cell').each(function () {
			$(this).find('.rw-row-snooze-group, .rw-row-snooze-inactive').replaceWith(snoozeCtrlHtml);
		});
	}

	window.RegistrationWatchUpdateMonitoringBanner = updateMonitoringBanner;

	function updateTimeDiagnostics(timeDiagnostics) {
		if (!timeDiagnostics) {
			return;
		}

		$('#rw-module-time').text(timeDiagnostics.module_time || '');
		$('#rw-database-time').text(timeDiagnostics.database_time || '');
	}

	$(function () {
		var root = $('.registrationwatch');
		var refreshButton = $('#rw-refresh');
		var alertSettingsDirty = false;
		var alertSettingsSavedThisSession = false;
		var refreshInFlight = false;
		var refreshTimer = null;

		function getPollInterval() {
			return parseInt(root.attr('data-poll-interval'), 10) || 0;
		}

		function hasAlertRecipients() {
			return $.trim(String($('#rw-alert-recipients').val() || '')) !== '';
		}

		function updateTestEmailState() {
			$('#rw-test-email').prop('disabled', alertSettingsDirty || !alertSettingsSavedThisSession || !hasAlertRecipients());
		}

		function markAlertSettingsDirty() {
			alertSettingsDirty = true;
			updateTestEmailState();
		}

		updateTestEmailState();

		$('#rw-alert-enabled, #rw-alert-recipients, #rw-alert-on-unreachable, #rw-alert-on-not-registered, #rw-alert-on-recovery, #rw-debounce-seconds, #rw-repeat-mode, #rw-storm-threshold')
			.on('input change', markAlertSettingsDirty);

		$('.registrationwatch').on('change', '.rw-enabled', function () {
			var input = $(this);
			var row = input.closest('tr');
			var registrationId = row.data('registration-id');
			var enabled = input.is(':checked') ? 1 : 0;
			var token = registrationWatchToken(root);

			if (!token) {
				input.prop('checked', !enabled);
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			row.toggleClass('rw-row-enabled', enabled === 1);
			input.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'setenabled',
					registration_id: registrationId,
					enabled: enabled,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					input.prop('checked', !enabled);
					row.toggleClass('rw-row-enabled', enabled === 0);
					showMessage(response && response.message ? response.message : 'Unable to save watch setting.', 'error');
				} else {
					if (response.monitoringState) {
						updateMonitoringBanner(response.monitoringState);
					}
					input.closest('td').html(buildMonitoredCellHtml(enabled));
				}
			}).fail(function () {
				input.prop('checked', !enabled);
				row.toggleClass('rw-row-enabled', enabled === 0);
				showMessage('Unable to save watch setting.', 'error');
			}).always(function () {
				// input may be detached if cell was rewritten on success; safe to ignore
				input.prop('disabled', false);
			});
		});

		$('.registrationwatch').on('change', '.rw-repeat-mode', function () {
			var select = $(this);
			var row = select.closest('tr');
			var registrationId = select.data('registration-id') || row.data('registration-id') || '';
			var repeatMode = select.val() || '';
			var previous = select.data('previous-value');
			var status = row.find('.rw-repeat-mode-status');
			var token = registrationWatchToken(root);

			if (previous === undefined) {
				previous = '';
			}
			if (!registrationId) {
				status.text('Save failed');
				return;
			}
			if (!token) {
				select.val(previous);
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			select.prop('disabled', true);
			status.text('Saving...');
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'setrepeatmode',
					registration_id: registrationId,
					repeat_mode: repeatMode,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					select.val(previous);
					status.text('Save failed');
					showMessage(response && response.message ? response.message : 'Unable to save repeat alert setting.', 'error');
					return;
				}
				select.data('previous-value', repeatMode);
				status.text('Saved');
				showMessage(response.message || 'Repeat alert setting saved.', 'success');
			}).fail(function () {
				select.val(previous);
				status.text('Save failed');
				showMessage('Unable to save repeat alert setting.', 'error');
			}).always(function () {
				select.prop('disabled', false);
			});
		});

		$('.rw-repeat-mode').each(function () {
			$(this).data('previous-value', $(this).val() || '');
		});

		function refreshStatus(isAutomatic) {
			var token = registrationWatchToken(root);
			if (!token) {
				if (!isAutomatic) {
					showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				}
				return;
			}
			if (refreshInFlight) {
				return;
			}
			refreshInFlight = true;
			if (refreshButton.length) {
				refreshButton.prop('disabled', true).addClass('disabled');
			}
			if (!isAutomatic) {
				showMessage('Refreshing registration status.', 'info');
			}
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: isAutomatic ? 'gettopology' : 'refresh',
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to refresh registration status.', 'error');
					return;
				}
				renderWatchedExtensionsTable(response.registrations);
				renderStatusRows(response.registrations);
				if (window.RegistrationWatchRenderRegistrationMap) {
					window.RegistrationWatchRenderRegistrationMap(response.registrations);
				}
				if (response.statusHistory) {
					renderHistoryRows(response.statusHistory);
				}
				if (response.alertHistory) {
					renderAlertHistoryRows(response.alertHistory);
				}
				updateTimeDiagnostics(response.timeDiagnostics);
				if (response.monitoringState) {
					updateMonitoringBanner(response.monitoringState);
				}
				if (!isAutomatic) {
					showMessage(response.message || 'Registration status refreshed.', 'success');
				}
			}).fail(function () {
				showMessage('Unable to refresh registration status.', 'error');
			}).always(function () {
				refreshInFlight = false;
				if (refreshButton.length) {
					refreshButton.prop('disabled', false).removeClass('disabled');
				}
			});
		}

		if (refreshButton.length) {
			refreshButton.on('click', function () {
				refreshStatus(false);
			});
		}

		function stopAutoRefresh() {
			if (refreshTimer) {
				clearInterval(refreshTimer);
				refreshTimer = null;
			}
		}

		function startAutoRefresh() {
			var pollInterval = getPollInterval();
			stopAutoRefresh();
			if (pollInterval <= 0) {
				return;
			}
			refreshStatus(true);
			refreshTimer = setInterval(function () {
				refreshStatus(true);
			}, pollInterval * 1000);
		}

		startAutoRefresh();

		$(window).on('unload', function () {
			stopAutoRefresh();
			stopSnoozeCountdown();
		});

		root.on('click', '.rw-disable-alerting', function () {
			var btn = $(this);
			var row = btn.closest('tr');
			var registrationId = btn.data('registration-id') || row.data('registration-id');
			var token = registrationWatchToken(root);

			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			btn.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'setenabled',
					registration_id: registrationId,
					enabled: 0,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to disable alerting.', 'error');
					btn.prop('disabled', false);
					return;
				}
				row.removeClass('rw-row-enabled');
				btn.closest('td').html(
					'<label class="rw-toggle">' +
					'<input type="checkbox" class="rw-enabled">' +
					'<span class="rw-toggle-slider"></span>' +
					'</label>'
				);
				if (response.monitoringState) {
					updateMonitoringBanner(response.monitoringState);
				}
			}).fail(function () {
				showMessage('Unable to disable alerting.', 'error');
				btn.prop('disabled', false);
			});
		});

		root.on('click', '.rw-snooze-btn', function () {
			var btn = $(this);
			var seconds = parseInt(btn.data('seconds'), 10) || 0;
			var token = registrationWatchToken(root);
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}
			btn.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'snoozemonitoring',
					seconds: seconds,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to snooze monitoring.', 'error');
					btn.prop('disabled', false);
					return;
				}
				updateMonitoringBanner(response.monitoringState);
			}).fail(function () {
				showMessage('Unable to snooze monitoring.', 'error');
				btn.prop('disabled', false);
			});
		});

		root.on('click', '.rw-resume-btn', function () {
			var btn = $(this);
			var token = registrationWatchToken(root);
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}
			btn.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'resumemonitoring',
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to resume monitoring.', 'error');
					btn.prop('disabled', false);
					return;
				}
				updateMonitoringBanner(response.monitoringState);
			}).fail(function () {
				showMessage('Unable to resume monitoring.', 'error');
				btn.prop('disabled', false);
			});
		});

		$(document).on('click', '.registrationwatch .rw-row-snooze-option', function (e) {
			e.preventDefault();
			var link = $(this);
			var row = link.closest('tr');
			var registrationId = row.data('registration-id');
			var seconds = parseInt(link.data('seconds'), 10);

			if (!seconds || [300, 900, 1800, 3600, 86400].indexOf(seconds) === -1) {
				return;
			}
			if (!registrationId) {
				showMessage('Unable to identify registration. Please reload the page and try again.', 'error');
				return;
			}
			var token = registrationWatchToken(root);
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'snoozeregistration',
					registration_id: registrationId,
					seconds: seconds,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to snooze registration.', 'error');
				}
			}).fail(function () {
				showMessage('Unable to snooze registration.', 'error');
			});
		});

		$('#rw-save-alerts').on('click', function () {
			var button = $(this);
			var token = registrationWatchToken(root);
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}
			button.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: alertSettingsPayload('savealerts', token)
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to save alert settings.', 'error');
					return;
				}
				startAutoRefresh();
				alertSettingsDirty = false;
				alertSettingsSavedThisSession = true;
				updateTestEmailState();
				showMessage(response.message || 'Alert settings saved.', 'success');
			}).fail(function () {
				showMessage('Unable to save alert settings.', 'error');
			}).always(function () {
				button.prop('disabled', false);
			});
		});

		$('#rw-test-email').off('click.registrationwatch').on('click.registrationwatch', function () {
			var button = $(this);
			var token = registrationWatchToken(root);
			if (button.prop('disabled') || alertSettingsDirty || !alertSettingsSavedThisSession || !hasAlertRecipients()) {
				updateTestEmailState();
				return;
			}
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}
			button.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'testemail',
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to send test email.', 'error');
					return;
				}
				renderAlertHistoryRows(response.alertHistory);
				showMessage(response.message || 'Test email attempted.', 'success');
			}).fail(function () {
				showMessage('Unable to send test email.', 'error');
			}).always(function () {
				updateTestEmailState();
			});
		});

		function markPruneApplied(button) {
			var restoreTimer = button.data('registrationwatch-prune-restore-timer');
			if (restoreTimer) {
				window.clearTimeout(restoreTimer);
			}

			button.text('✓ Applied');
			button.data('registrationwatch-prune-restore-timer', window.setTimeout(function () {
				updatePruneControlState(button.closest('.rw-prune-control'));
				button.removeData('registrationwatch-prune-restore-timer');
			}, 3000));
		}

		function updatePruneControlState(control, resetConfirmation) {
			if (resetConfirmation === undefined) {
				resetConfirmation = true;
			}
			var selectedPolicy = String(control.find('.rw-prune-policy').val() || 'never').toLowerCase();
			var activePolicy = String(control.attr('data-active-policy') || 'never').toLowerCase();
			var isActive = selectedPolicy === activePolicy;
			var button = control.find('.rw-apply-prune');

			button.text(isActive ? 'Active' : 'Apply');
			button.prop('disabled', isActive);
			button.toggleClass('disabled', isActive);
			if (resetConfirmation) {
				control.find('.rw-prune-confirm').prop('checked', false);
			}
			control.find('.rw-prune-confirm-wrap').toggle(!isActive && selectedPolicy !== 'never');
		}

		$('.registrationwatch .rw-prune-control').each(function () {
			updatePruneControlState($(this));
		});

		root.off('change.registrationwatchPrune', '.rw-prune-policy').on('change.registrationwatchPrune', '.rw-prune-policy', function () {
			var control = $(this).closest('.rw-prune-control');
			updatePruneControlState(control);
		});

		root.off('click.registrationwatchPrune', '.rw-apply-prune').on('click.registrationwatchPrune', '.rw-apply-prune', function () {
			var button = $(this);
			var control = button.closest('.rw-prune-control');
			var historyType = control.data('history-type') || '';
			var policy = String(control.find('.rw-prune-policy').val() || 'never').toLowerCase();
			var activePolicy = String(control.attr('data-active-policy') || 'never').toLowerCase();
			var confirmed = control.find('.rw-prune-confirm').is(':checked') ? 1 : 0;
			var token = registrationWatchToken(root);
			var applied = false;

			if (button.data('registrationwatch-prune-in-flight')) {
				return;
			}
			if (policy === activePolicy) {
				updatePruneControlState(control);
				return;
			}
			if (policy !== 'never' && !confirmed) {
				showMessage('Confirm permanent deletion before applying pruning.', 'error');
				return;
			}
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			button.data('registrationwatch-prune-in-flight', true).prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'saveprunepolicy',
					history_type: historyType,
					policy: policy,
					confirmed: confirmed,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to save pruning policy.', 'error');
					return;
				}

				control.find('.rw-prune-confirm').prop('checked', false);
				control.find('.rw-prune-confirm-wrap').hide();
				if (response.statusHistory) {
					renderHistoryRows(response.statusHistory);
				}
				if (response.alertHistory) {
					renderAlertHistoryRows(response.alertHistory);
				}
				control.attr('data-active-policy', policy);
				applied = true;
				markPruneApplied(button);
				showMessage(response.message || 'History pruning policy saved.', 'success');
			}).fail(function () {
				showMessage('Unable to save pruning policy.', 'error');
			}).always(function () {
				button.removeData('registrationwatch-prune-in-flight');
				if (!button.data('registrationwatch-prune-restore-timer')) {
					updatePruneControlState(control, applied);
				}
			});
		});

		root.off('click.registrationwatchDeleteStatusHistory', '.rw-delete-status-history').on('click.registrationwatchDeleteStatusHistory', '.rw-delete-status-history', function () {
			var button = $(this);
			var id = parseInt(button.data('history-id'), 10) || 0;
			var token = registrationWatchToken(root);
			if (button.data('registrationwatch-delete-in-flight')) {
				return;
			}
			if (id <= 0 || !window.confirm('Permanently delete this Status History row?')) {
				return;
			}
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			button.data('registrationwatch-delete-in-flight', true).prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'deletestatushistoryrow',
					id: id,
					confirmed: 1,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to delete Status History row.', 'error');
					return;
				}
				renderHistoryRows(response.statusHistory);
				showMessage(response.message || 'Status History row deleted.', 'success');
			}).fail(function () {
				showMessage('Unable to delete Status History row.', 'error');
			}).always(function () {
				button.removeData('registrationwatch-delete-in-flight').prop('disabled', false);
			});
		});

		root.off('click.registrationwatchDeleteAlertHistory', '.rw-delete-alert-history').on('click.registrationwatchDeleteAlertHistory', '.rw-delete-alert-history', function () {
			var button = $(this);
			var id = parseInt(button.data('history-id'), 10) || 0;
			var token = registrationWatchToken(root);
			if (button.data('registrationwatch-delete-in-flight')) {
				return;
			}
			if (id <= 0 || !window.confirm('Permanently delete this Alert History row?')) {
				return;
			}
			if (!token) {
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			button.data('registrationwatch-delete-in-flight', true).prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'deletealerthistoryrow',
					id: id,
					confirmed: 1,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to delete Alert History row.', 'error');
					return;
				}
				renderAlertHistoryRows(response.alertHistory);
				showMessage(response.message || 'Alert History row deleted.', 'success');
			}).fail(function () {
				showMessage('Unable to delete Alert History row.', 'error');
			}).always(function () {
				button.removeData('registrationwatch-delete-in-flight').prop('disabled', false);
			});
		});
	});
}(jQuery));


/* Registration Watch notes autosave */
(function($) {
        if (!$) {
                return;
        }

        var noteTimers = {};
        var noteRequestIds = {};

        function registrationWatchToken() {
                if (window.RegistrationWatchToken) {
                        return window.RegistrationWatchToken();
                }

                return $('input[name="token"]').first().val()
                        || $('input[name="csrf_token"]').first().val()
                        || $('input[name="freepbx_token"]').first().val()
                        || '';
        }

        function saveRegistrationNote(input) {
                var $input = $(input);
                var registrationId = $input.data('registration-id') || '';
                var $status = $input.closest('td').find('.rw-notes-status');
                var value = $input.val() || '';

                if (value.length > 72) {
                        value = value.substring(0, 72);
                        $input.val(value);
                }

                if (!registrationId) {
                        $status.text('Save failed');
                        return;
                }

                if (!registrationWatchToken()) {
                        $status.text('Save failed');
                        return;
                }

                noteRequestIds[registrationId] = (noteRequestIds[registrationId] || 0) + 1;
                var requestId = noteRequestIds[registrationId];

                $status.text('Saving...');

                $.ajax({
                        url: 'ajax.php?module=registrationwatch&command=savenotes',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                                registration_id: registrationId,
                                notes: value,
                                token: registrationWatchToken()
                        },
                        timeout: 10000
                }).done(function(response) {
                        if (requestId !== noteRequestIds[registrationId]) {
                                return;
                        }

                        if (response && response.status) {
                                if (response.notes_updated_at) {
                                        $status.text('Saved ' + response.notes_updated_at);
                                } else {
                                        $status.text('Cleared');
                                }
                                return;
                        }

                        $status.text('Save failed');
                }).fail(function() {
                        if (requestId === noteRequestIds[registrationId]) {
                                $status.text('Save failed');
                        }
                });
        }

        $(document).on('input', '.registrationwatch .rw-registration-notes', function() {
                var input = this;
                var registrationId = $(input).data('registration-id') || '';

                clearTimeout(noteTimers[registrationId]);
                noteTimers[registrationId] = setTimeout(function() {
                        saveRegistrationNote(input);
                }, 700);
        });
})(window.jQuery);


/* Registration Watch shared show limits */
(function($) {
        if (!$) {
                return;
        }

        var allowedLimits = ['6', '30', '60', '120', 'all'];
        var currentLimit = String($('#rw-map-limit').val() || '6').toLowerCase();

        function registrationWatchToken() {
                if (window.RegistrationWatchToken) {
                        return window.RegistrationWatchToken();
                }

                return $('input[name="token"]').first().val()
                        || $('input[name="csrf_token"]').first().val()
                        || $('input[name="freepbx_token"]').first().val()
                        || '';
        }

        function normaliseLimit(value) {
                value = String(value || '6').toLowerCase();
                return allowedLimits.indexOf(value) === -1 ? '6' : value;
        }

        function controlHtml(section) {
                return '<div class="form-inline rw-show-control" data-show-section="' + section + '">'
                        + '<label>Show</label>'
                        + '<select class="form-control input-sm rw-shared-show-limit" style="width:auto;">'
                        + '<option value="6">6</option>'
                        + '<option value="30">30</option>'
                        + '<option value="60">60</option>'
                        + '<option value="120">120</option>'
                        + '<option value="all">All</option>'
                        + '</select>'
                        + '<span class="text-muted rw-show-count"></span>'
                        + '</div>';
        }

        function panelByTitle(title) {
                var found = $();
                $('.registrationwatch .panel-title').each(function() {
                        if ($.trim($(this).text()) === title) {
                                found = $(this).closest('.panel');
                                return false;
                        }
                });
                return found;
        }

	        function installTableControls() {
	                [
	                        ['watched', 'Watched Extensions'],
	                        ['status-history', 'Status History'],
	                        ['alert-history', 'Alert History']
                ].forEach(function(item) {
                        var section = item[0];
                        var title = item[1];
                        var $panel = panelByTitle(title);

                        if (!$panel.length || $panel.find('.rw-show-control').length) {
                                return;
                        }

                        var $table = $panel.find('table').first();
	                        if (!$table.length) {
	                                return;
	                        }

	                        var $control = $(controlHtml(section));
	                        var $slot = $panel.find('.rw-history-show-slot[data-show-section="' + section + '"]').first();
	                        if ($slot.length) {
	                                $slot.empty().append($control);
	                        } else {
	                                $table.before($control);
	                        }
	                });
	        }

        function syncControls(value) {
                currentLimit = normaliseLimit(value);
                $('.rw-shared-show-limit').val(currentLimit);
        }

        function applyTableLimit($panel) {
                var $rows = $panel.find('tbody tr');
                var total = $rows.length;
                var shown = total;

                if (currentLimit !== 'all') {
                        shown = parseInt(currentLimit, 10);
                        $rows.hide().slice(0, shown).show();
                        shown = Math.min(shown, total);
                } else {
                        $rows.show();
                }

                if ($panel.find('.rw-show-control[data-show-section="watched"]').length) {
                        $panel.find('.rw-show-count').text('Showing ' + shown + ' of ' + total + ' watched extensions');
                } else {
                        $panel.find('.rw-show-count').text('Showing ' + shown + ' of ' + total);
                }
        }

        function applyAllLimits(triggerMapChange) {
                ['Watched Extensions', 'Status History', 'Alert History'].forEach(function(title) {
                        var $panel = panelByTitle(title);
                        if ($panel.length) {
                                applyTableLimit($panel);
                        }
                });

                if (triggerMapChange && $('#rw-map-limit').length) {
                        $('#rw-map-limit').val(currentLimit).trigger('change');
                }
        }

        function saveShowLimit(value) {
                var token = registrationWatchToken();
                if (!token) {
                        return;
                }

                $.ajax({
                        url: 'ajax.php?module=registrationwatch&command=saveshowlimit',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                                show_limit: normaliseLimit(value),
                                token: token
                        },
                        timeout: 10000
                });
        }

        $(function() {
                installTableControls();

                $('#rw-map-limit')
                        .addClass('rw-shared-show-limit')
                        .attr('data-section', 'map');

                syncControls(currentLimit);
                applyAllLimits(false);
        });

        $(document).on('change', '.rw-shared-show-limit', function() {
                var value = normaliseLimit($(this).val());

                syncControls(value);
                applyAllLimits(this.id !== 'rw-map-limit');
                saveShowLimit(value);
        });

        $(document).on('registrationwatch:history-rendered', function() {
                installTableControls();
                syncControls(currentLimit);
                applyAllLimits(false);
        });
})(window.jQuery);


/* Registration Watch temporary overlay messages */
(function($) {
        if (!$) {
                return;
        }

        function toastContainer() {
                var $container = $('.rw-toast-container');

                if (!$container.length) {
                        $container = $('<div class="rw-toast-container" aria-live="polite" aria-atomic="true"></div>');
                        $('body').append($container);
                }

                return $container;
        }

        function normaliseType(type) {
                type = String(type || 'info').toLowerCase();

                if (type === 'fail' || type === 'failed' || type === 'error') {
                        return 'danger';
                }

                return type;
        }

        function showToast(message, type) {
                message = $.trim(String(message || ''));

                if (!message) {
                        return;
                }

                type = normaliseType(type);

                var $toast = $('<div class="rw-toast"></div>')
                        .addClass('rw-toast-' + type)
                        .text(message);

                toastContainer().append($toast);

                window.setTimeout(function() {
                        $toast.addClass('rw-toast-visible');
                }, 20);

                window.setTimeout(function() {
                        $toast.addClass('rw-toast-hiding');

                        window.setTimeout(function() {
                                $toast.remove();
                        }, 220);
                }, 2800);
        }

        window.RegistrationWatchToast = showToast;

        function messageTypeFromElement($el) {
                if ($el.hasClass('alert-danger') || $el.hasClass('alert-error')) {
                        return 'danger';
                }

                if ($el.hasClass('alert-warning')) {
                        return 'warning';
                }

                if ($el.hasClass('alert-success')) {
                        return 'success';
                }

                return 'info';
        }

        function shouldBridgeElement(el) {
                var $el = $(el);

                if (!$el.length || !$el.closest('.registrationwatch').length) {
                        return false;
                }

                if ($el.is('.rw-notes-status, .rw-show-count, .rw-placeholder')) {
                        return false;
                }

                if ($el.closest('.rw-notes-status, .rw-show-count, .rw-placeholder').length) {
                        return false;
                }

                var idClass = String(($el.attr('id') || '') + ' ' + ($el.attr('class') || '')).toLowerCase();

                return idClass.indexOf('message') !== -1
                        || idClass.indexOf('status-message') !== -1
                        || idClass.indexOf('ajax-message') !== -1;
        }

        function bridgeElement(el) {
                var $el = $(el);

                if (!shouldBridgeElement(el)) {
                        return;
                }

                var message = $.trim($el.text());

                if (!message) {
                        return;
                }

                if ($el.data('rw-toast-message') === message) {
                        return;
                }

                $el.data('rw-toast-message', message);
                showToast(message, messageTypeFromElement($el));
                $el.hide();
        }

        function scanMessages(root) {
                var $root = $(root);

                if (shouldBridgeElement(root)) {
                        bridgeElement(root);
                }

                $root.find('#rw-message, #rw-status-message, #rw-ajax-message, .rw-message, .rw-status-message, .rw-ajax-message, [id*="message"], [class*="message"]').each(function() {
                        bridgeElement(this);
                });
        }

        $(function() {
                var target = document.querySelector('.registrationwatch');

                if (!target) {
                        return;
                }

                scanMessages(target);

                if (!window.MutationObserver) {
                        return;
                }

                var observer = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                                if (mutation.type === 'characterData' && mutation.target.parentNode) {
                                        bridgeElement(mutation.target.parentNode);
                                        return;
                                }

                                $(mutation.addedNodes).each(function() {
                                        if (this.nodeType === 1) {
                                                scanMessages(this);
                                        }
                                });

                                if (mutation.target && mutation.target.nodeType === 1) {
                                        bridgeElement(mutation.target);
                                }
                        });
                });

                observer.observe(target, {
                        childList: true,
                        subtree: true,
                        characterData: true
                });
        });
})(window.jQuery);
