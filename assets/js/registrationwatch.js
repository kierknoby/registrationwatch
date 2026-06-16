/**
 * Registration Watch admin interactions.
 */
(function ($) {
	'use strict';

	function escapeHtml(value) {
		return $('<div>').text(value === null || value === undefined || value === '' ? '-' : String(value)).html();
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
		var el = $('#rw-message');
		el.removeClass('alert-success alert-danger alert-info');
		if (!message) {
			el.hide();
			return;
		}
		el.addClass(level === 'error' ? 'alert-danger' : (level === 'info' ? 'alert-info' : 'alert-success'));
		el.text(message).show();
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

	function registrationRows(extension) {
		return $('.registrationwatch tr[data-extension]').filter(function () {
			return String($(this).data('extension')) === String(extension);
		});
	}

	function setToggleText(input) {
		var label = input.closest('.rw-toggle').find('span');
		label.text(input.is(':checked') ? 'Selected' : 'Not selected');
	}

	function renderStatusRows(registrations) {
		$.each(registrations || [], function (_, registration) {
			var rows = registrationRows(registration.extension);
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

	function renderHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			var latency = entry.latency_ms ? escapeHtml(entry.latency_ms) + ' ms' : '-';
			var id = parseInt(entry.id, 10) || 0;
			rows.push(
				'<tr data-history-id="' + id + '">' +
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

	function renderAlertHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			var id = parseInt(entry.id, 10) || 0;
			rows.push(
				'<tr data-history-id="' + id + '">' +
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

	$(function () {
		var root = $('.registrationwatch');
		var refreshButton = $('#rw-refresh');
		var refreshInFlight = false;
		var refreshTimer = null;

		function getPollInterval() {
			return parseInt(root.attr('data-poll-interval'), 10) || 0;
		}

		$('.rw-enabled').each(function () {
			setToggleText($(this));
		});

		$('.registrationwatch').on('change', '.rw-enabled', function () {
			var input = $(this);
			var row = input.closest('tr');
			var extension = row.data('extension');
			var enabled = input.is(':checked') ? 1 : 0;
			var token = registrationWatchToken(root);

			if (!token) {
				input.prop('checked', !enabled);
				setToggleText(input);
				showMessage('Security token unavailable. Please reload the page and try again.', 'error');
				return;
			}

			input.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=registrationwatch',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'setenabled',
					extension: extension,
					enabled: enabled,
					token: token
				}
			}).done(function (response) {
				if (!response || !response.status) {
					input.prop('checked', !enabled);
					showMessage(response && response.message ? response.message : 'Unable to save watch setting.', 'error');
				} else {
					showMessage(response.message || 'Watch setting saved.', 'success');
				}
				setToggleText(input);
			}).fail(function () {
				input.prop('checked', !enabled);
				setToggleText(input);
				showMessage('Unable to save watch setting.', 'error');
			}).always(function () {
				input.prop('disabled', false);
			});
		});

		$('.registrationwatch').on('change', '.rw-repeat-mode', function () {
			var select = $(this);
			var row = select.closest('tr');
			var extension = select.data('extension') || row.data('extension') || '';
			var repeatMode = select.val() || '';
			var previous = select.data('previous-value');
			var status = row.find('.rw-repeat-mode-status');
			var token = registrationWatchToken(root);

			if (previous === undefined) {
				previous = '';
			}
			if (!extension) {
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
					extension: extension,
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
				button.prop('disabled', false);
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
                var extension = $input.data('extension') || '';
                var $status = $input.closest('td').find('.rw-notes-status');
                var value = $input.val() || '';

                if (value.length > 48) {
                        value = value.substring(0, 48);
                        $input.val(value);
                }

                if (!extension) {
                        $status.text('Save failed');
                        return;
                }

                if (!registrationWatchToken()) {
                        $status.text('Save failed');
                        return;
                }

                noteRequestIds[extension] = (noteRequestIds[extension] || 0) + 1;
                var requestId = noteRequestIds[extension];

                $status.text('Saving...');

                $.ajax({
                        url: 'ajax.php?module=registrationwatch&command=savenotes',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                                extension: extension,
                                notes: value,
                                token: registrationWatchToken()
                        },
                        timeout: 10000
                }).done(function(response) {
                        if (requestId !== noteRequestIds[extension]) {
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
                        if (requestId === noteRequestIds[extension]) {
                                $status.text('Save failed');
                        }
                });
        }

        $(document).on('input', '.registrationwatch .rw-registration-notes', function() {
                var input = this;
                var extension = $(input).data('extension') || '';

                clearTimeout(noteTimers[extension]);
                noteTimers[extension] = setTimeout(function() {
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
	                        ['watched', 'Watched extensions'],
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

                $panel.find('.rw-show-count').text('Showing ' + shown + ' of ' + total);
        }

        function applyAllLimits(triggerMapChange) {
                ['Watched extensions', 'Status History', 'Alert History'].forEach(function(title) {
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
