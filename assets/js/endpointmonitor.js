/**
 * EndPoint Monitor admin interactions.
 */
(function ($) {
	'use strict';

	function escapeHtml(value) {
		return $('<div>').text(value === null || value === undefined || value === '' ? '-' : String(value)).html();
	}

	function showMessage(message, level) {
		var el = $('#em-message');
		el.removeClass('alert-success alert-danger alert-info');
		if (!message) {
			el.hide();
			return;
		}
		el.addClass(level === 'error' ? 'alert-danger' : (level === 'info' ? 'alert-info' : 'alert-success'));
		el.text(message).show();
	}

	function endpointRows(extension) {
		return $('.endpointmonitor tr[data-extension]').filter(function () {
			return String($(this).data('extension')) === String(extension);
		});
	}

	function setToggleText(input) {
		var label = input.closest('.em-toggle').find('span');
		label.text(input.is(':checked') ? 'Selected' : 'Not selected');
	}

	function renderStatusRows(endpoints) {
		$.each(endpoints || [], function (_, endpoint) {
			var rows = endpointRows(endpoint.extension);
			rows.find('.em-status-value').text(endpoint.last_known_status || '-');
			rows.find('.em-device-name').text(endpoint.device_name || '-');
			rows.find('.em-firmware-version').text(endpoint.firmware_version || '-');
			rows.find('.em-source-ip').text(endpoint.source_ip || '-');
			rows.find('.em-source-port').text(endpoint.source_port || '-');
			rows.find('.em-contact-uri').html(escapeHtml(endpoint.contact_uri));
			rows.find('.em-last-seen').text(endpoint.last_seen_at || '-');
			rows.find('.em-last-checked').text(endpoint.last_checked_at || '-');
			if (endpoint.latency_ms) {
				rows.find('.em-latency').text(endpoint.latency_ms + ' ms');
			} else if (endpoint.last_known_status === 'Registered (No Qualify)') {
				rows.find('.em-latency').text('Unavailable; qualify is not enabled.');
			} else {
				rows.find('.em-latency').text('-');
			}
		});
	}

	function renderHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			var latency = entry.latency_ms ? escapeHtml(entry.latency_ms) + ' ms' : '-';
			rows.push(
				'<tr>' +
					'<td>' + escapeHtml(entry.created_at) + '</td>' +
					'<td><code>' + escapeHtml(entry.extension) + '</code></td>' +
					'<td>' + escapeHtml(entry.from_state) + '</td>' +
					'<td>' + escapeHtml(entry.to_state) + '</td>' +
					'<td>' + escapeHtml(entry.source) + '</td>' +
					'<td>' + escapeHtml(entry.reason) + '</td>' +
					'<td>' + latency + '</td>' +
				'</tr>'
			);
		});

		$('.em-history tbody').html(rows.join(''));
		$('.em-history-empty').toggle(rows.length === 0);
		$('.em-history-wrap').toggle(rows.length > 0);
	}

	function renderAlertHistoryRows(history) {
		var rows = [];
		$.each(history || [], function (_, entry) {
			rows.push(
				'<tr>' +
					'<td>' + escapeHtml(entry.sent_at) + '</td>' +
					'<td><code>' + escapeHtml(entry.extension) + '</code></td>' +
					'<td>' + escapeHtml(entry.alert_type) + '</td>' +
					'<td>' + escapeHtml(entry.status) + '</td>' +
					'<td>' + escapeHtml(entry.recipient) + '</td>' +
					'<td>' + escapeHtml(entry.result) + '</td>' +
					'<td>' + escapeHtml(entry.error) + '</td>' +
				'</tr>'
			);
		});

		$('.em-alert-history tbody').html(rows.join(''));
		$('.em-alert-history-empty').toggle(rows.length === 0);
		$('.em-alert-history-wrap').toggle(rows.length > 0);
	}

	function alertSettingsPayload(command, csrfToken) {
		return {
			command: command,
			token: csrfToken,
			alert_enabled: $('#em-alert-enabled').is(':checked') ? 1 : 0,
			alert_recipients: $('#em-alert-recipients').val(),
			alert_on_unreachable: $('#em-alert-on-unreachable').is(':checked') ? 1 : 0,
			alert_on_not_registered: $('#em-alert-on-not-registered').is(':checked') ? 1 : 0,
			alert_on_recovery: $('#em-alert-on-recovery').is(':checked') ? 1 : 0,
			debounce_seconds: $('#em-debounce-seconds').val(),
			repeat_suppression_seconds: $('#em-repeat-suppression-seconds').val()
		};
	}

	$(function () {
		var root = $('.endpointmonitor');
		var csrfToken = root.data('csrf-token') || '';
		var refreshInFlight = false;
		var refreshTimer = null;

		function getPollInterval() {
			return parseInt(root.attr('data-poll-interval'), 10) || 0;
		}

		$('.em-enabled').each(function () {
			setToggleText($(this));
		});

		$('.endpointmonitor').on('change', '.em-enabled', function () {
			var input = $(this);
			var row = input.closest('tr');
			var extension = row.data('extension');
			var enabled = input.is(':checked') ? 1 : 0;

			input.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=endpointmonitor',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'setenabled',
					extension: extension,
					enabled: enabled,
					token: csrfToken
				}
			}).done(function (response) {
				if (!response || !response.status) {
					input.prop('checked', !enabled);
					showMessage(response && response.message ? response.message : 'Unable to save endpoint setting.', 'error');
				} else {
					showMessage(response.message || 'Endpoint setting saved.', 'success');
				}
				setToggleText(input);
			}).fail(function () {
				input.prop('checked', !enabled);
				setToggleText(input);
				showMessage('Unable to save endpoint setting.', 'error');
			}).always(function () {
				input.prop('disabled', false);
			});
		});

		function refreshStatus(isAutomatic) {
			var button = $('#em-refresh');
			if (refreshInFlight) {
				return;
			}
			refreshInFlight = true;
			button.prop('disabled', true).addClass('disabled');
			if (!isAutomatic) {
				showMessage('Refreshing endpoint status.', 'info');
			}
			$.ajax({
				url: 'ajax.php?module=endpointmonitor',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'refresh',
					token: csrfToken
				}
			}).done(function (response) {
				if (!response || !response.status) {
					showMessage(response && response.message ? response.message : 'Unable to refresh endpoint status.', 'error');
					return;
				}
				renderStatusRows(response.endpoints);
				if (window.EndpointMonitorRenderEndpointMap) {
					window.EndpointMonitorRenderEndpointMap(response.endpoints);
				}
				renderHistoryRows(response.statusHistory);
				renderAlertHistoryRows(response.alertHistory);
				if (!isAutomatic) {
					showMessage(response.message || 'Endpoint status refreshed.', 'success');
				}
			}).fail(function () {
				showMessage('Unable to refresh endpoint status.', 'error');
			}).always(function () {
				refreshInFlight = false;
				button.prop('disabled', false).removeClass('disabled');
			});
		}

		$('#em-refresh').on('click', function () {
			refreshStatus(false);
		});

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

		$('#em-save-alerts').on('click', function () {
			var button = $(this);
			button.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=endpointmonitor',
				method: 'POST',
				dataType: 'json',
				data: alertSettingsPayload('savealerts', csrfToken)
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

		$('#em-test-email').off('click.endpointmonitor').on('click.endpointmonitor', function () {
			var button = $(this);
			button.prop('disabled', true);
			$.ajax({
				url: 'ajax.php?module=endpointmonitor',
				method: 'POST',
				dataType: 'json',
				data: {
					command: 'testemail',
					token: csrfToken
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
	});
}(jQuery));


/* EndPoint Monitor notes autosave */
(function($) {
        if (!$) {
                return;
        }

        var noteTimers = {};
        var noteRequestIds = {};

        function endpointMonitorToken() {
                if (typeof csrfToken !== 'undefined' && csrfToken) {
                        return csrfToken;
                }

                return $('input[name="token"]').first().val()
                        || $('input[name="csrf_token"]').first().val()
                        || $('input[name="freepbx_token"]').first().val()
                        || '';
        }

        function saveEndpointNote(input) {
                var $input = $(input);
                var extension = $input.data('extension') || '';
                var $status = $input.closest('td').find('.em-notes-status');
                var value = $input.val() || '';

                if (value.length > 48) {
                        value = value.substring(0, 48);
                        $input.val(value);
                }

                if (!extension) {
                        $status.text('Save failed');
                        return;
                }

                noteRequestIds[extension] = (noteRequestIds[extension] || 0) + 1;
                var requestId = noteRequestIds[extension];

                $status.text('Saving...');

                $.ajax({
                        url: 'ajax.php?module=endpointmonitor&command=savenotes',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                                extension: extension,
                                notes: value,
                                token: endpointMonitorToken()
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

        $(document).on('input', '.endpointmonitor .em-endpoint-notes', function() {
                var input = this;
                var extension = $(input).data('extension') || '';

                clearTimeout(noteTimers[extension]);
                noteTimers[extension] = setTimeout(function() {
                        saveEndpointNote(input);
                }, 700);
        });
})(window.jQuery);


/* EndPoint Monitor shared show limits */
(function($) {
        if (!$) {
                return;
        }

        var allowedLimits = ['6', '30', '60', '120', 'all'];
        var currentLimit = String($('#em-map-limit').val() || '6').toLowerCase();

        function endpointMonitorToken() {
                if (typeof csrfToken !== 'undefined' && csrfToken) {
                        return csrfToken;
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
                return '<div class="form-inline em-show-control" data-show-section="' + section + '">'
                        + '<label>Show</label>'
                        + '<select class="form-control input-sm em-shared-show-limit" style="width:auto;">'
                        + '<option value="6">6</option>'
                        + '<option value="30">30</option>'
                        + '<option value="60">60</option>'
                        + '<option value="120">120</option>'
                        + '<option value="all">All</option>'
                        + '</select>'
                        + '<span class="text-muted em-show-count"></span>'
                        + '</div>';
        }

        function panelByTitle(title) {
                var found = $();
                $('.endpointmonitor .panel-title').each(function() {
                        if ($.trim($(this).text()) === title) {
                                found = $(this).closest('.panel');
                                return false;
                        }
                });
                return found;
        }

        function installTableControls() {
                [
                        ['monitored', 'Monitored Endpoints'],
                        ['status-history', 'Status History'],
                        ['alert-history', 'Alert History']
                ].forEach(function(item) {
                        var section = item[0];
                        var title = item[1];
                        var $panel = panelByTitle(title);

                        if (!$panel.length || $panel.find('.em-show-control').length) {
                                return;
                        }

                        var $table = $panel.find('table').first();
                        if (!$table.length) {
                                return;
                        }

                        $table.before(controlHtml(section));
                });
        }

        function syncControls(value) {
                currentLimit = normaliseLimit(value);
                $('.em-shared-show-limit').val(currentLimit);
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

                $panel.find('.em-show-count').text('Showing ' + shown + ' of ' + total);
        }

        function applyAllLimits(triggerMapChange) {
                ['Monitored Endpoints', 'Status History', 'Alert History'].forEach(function(title) {
                        var $panel = panelByTitle(title);
                        if ($panel.length) {
                                applyTableLimit($panel);
                        }
                });

                if (triggerMapChange && $('#em-map-limit').length) {
                        $('#em-map-limit').val(currentLimit).trigger('change');
                }
        }

        function saveShowLimit(value) {
                $.ajax({
                        url: 'ajax.php?module=endpointmonitor&command=saveshowlimit',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                                show_limit: normaliseLimit(value),
                                token: endpointMonitorToken()
                        },
                        timeout: 10000
                });
        }

        $(function() {
                installTableControls();

                $('#em-map-limit')
                        .addClass('em-shared-show-limit')
                        .attr('data-section', 'map');

                syncControls(currentLimit);
                applyAllLimits(false);
        });

        $(document).on('change', '.em-shared-show-limit', function() {
                var value = normaliseLimit($(this).val());

                syncControls(value);
                applyAllLimits(this.id !== 'em-map-limit');
                saveShowLimit(value);
        });
})(window.jQuery);


/* EndPoint Monitor temporary overlay messages */
(function($) {
        if (!$) {
                return;
        }

        function toastContainer() {
                var $container = $('.em-toast-container');

                if (!$container.length) {
                        $container = $('<div class="em-toast-container" aria-live="polite" aria-atomic="true"></div>');
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

                var $toast = $('<div class="em-toast"></div>')
                        .addClass('em-toast-' + type)
                        .text(message);

                toastContainer().append($toast);

                window.setTimeout(function() {
                        $toast.addClass('em-toast-visible');
                }, 20);

                window.setTimeout(function() {
                        $toast.addClass('em-toast-hiding');

                        window.setTimeout(function() {
                                $toast.remove();
                        }, 220);
                }, 2800);
        }

        window.EndPointMonitorToast = showToast;

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

                if (!$el.length || !$el.closest('.endpointmonitor').length) {
                        return false;
                }

                if ($el.is('.em-notes-status, .em-show-count, .em-placeholder')) {
                        return false;
                }

                if ($el.closest('.em-notes-status, .em-show-count, .em-placeholder').length) {
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

                if ($el.data('em-toast-message') === message) {
                        return;
                }

                $el.data('em-toast-message', message);
                showToast(message, messageTypeFromElement($el));
                $el.hide();
        }

        function scanMessages(root) {
                var $root = $(root);

                if (shouldBridgeElement(root)) {
                        bridgeElement(root);
                }

                $root.find('#em-message, #em-status-message, #em-ajax-message, .em-message, .em-status-message, .em-ajax-message, [id*="message"], [class*="message"]').each(function() {
                        bridgeElement(this);
                });
        }

        $(function() {
                var target = document.querySelector('.endpointmonitor');

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
