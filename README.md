# Registration Watch for FreePBX 17

Registration Watch (`registrationwatch`) watches SIP/PJSIP registration state in
FreePBX/PBXact 17. It discovers local PJSIP extensions, records registration
state changes, and can send email alerts when watched extensions become
unavailable or recover.

Where available, Registration Watch also shows supporting details such as IP
address, user-agent, and firmware information. These details depend on what
Asterisk exposes for the registration and may not be available for every phone
or system.

## Development Branch Warning

This branch is in active development. Use the `main` branch for stable releases
unless you are explicitly testing 1.2.0 development changes.

## Compatibility

Use with FreePBX/PBXact 17 only. Do not install on FreePBX/PBXact 16 or below.

## Requirements

* FreePBX/PBXact 17
* PJSIP channel driver
* Existing FreePBX PJSIP extensions/devices
* Asterisk Manager access available to FreePBX
* FreePBX Job runner enabled for scheduled background checks
* FreePBX mail support configured if alert email delivery is required

## Installing

Place the `registrationwatch` directory in `/var/www/html/admin/modules/`, then:

```sh
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

For a developer install from this repository:

```sh
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/registrationwatch.git registrationwatch
fwconsole ma installlocal registrationwatch
fwconsole chown
fwconsole reload
```

The module appears under **Reports > Registration Watch**.

## Updating Registration Watch

Do not uninstall when updating. Uninstalling removes Registration Watch tables.

Check the installed version:

```sh
fwconsole ma list | grep -i registrationwatch
grep -n "<version>" /var/www/html/admin/modules/registrationwatch/module.xml
```

Update the files, then run:

```sh
fwconsole ma install registrationwatch
fwconsole chown
fwconsole reload
```

## Background Checks

Registration Watch registers a FreePBX job named:

```text
registrationwatch :: monitor
```

Useful checks:

```sh
fwconsole job --list | grep -i registrationwatch
fwconsole job --run=<job_id> --force
```

Expected job output includes:

```text
Running registrationwatch :: monitor ...
Registration Watch background job completed.
```

The module does not install a daemon, systemd unit, AMI event listener, custom
probe service, webhook sender, or SMS sender.

## Data Model

The canonical Registration Watch table names are:

* `registrationwatch_registrations`
* `registrationwatch_status_history`
* `registrationwatch_settings`
* `registrationwatch_alert_history`
* `registrationwatch_alert_escalation`

`registrationwatch_registrations` stores watched FreePBX PJSIP extension entries,
including discovered contact details, watch toggles, admin notes, and the latest
status snapshot.

`registrationwatch_status_history` stores transition rows created during
reconciliation.

`registrationwatch_settings` stores simple key/value settings, including polling
interval, UI show limits, alert configuration, and history pruning policies.

`registrationwatch_alert_history` stores one row per recipient and alert
decision. The unique key `registrationwatch_alert_unique_transition_recipient`
prevents repeated handling of the same transition, alert type, and recipient.
Storm-suppressed rows are also stored here so the audit trail shows which
individual messages were not sent because a Storm Summary was used.

`registrationwatch_alert_escalation` stores repeat-alert reminder state for
registrations that remain unavailable, tracking when the next reminder is due
under the configured repeat mode.

## Alerting

Alerts are generated from reconciliation-created transition rows.

Discovered extensions are listed in the Watched Extensions table but not
monitored by default. Enable the Monitored toggle for any extension that should
generate alerts.

Defaults:

* Alerts disabled
* Recipients empty
* Alert on unreachable enabled
* Alert on not registered enabled
* Alert on recovery enabled
* Debounce seconds: `0`, maximum `86400`
* Repeat alerts: `Never`
* Storm Threshold: `20`
* Auto-disable absent registrations: `2592000` seconds, 30 days

Alertable transitions:

* Reachable or Registered (no qualify) to Unreachable
* Reachable, Registered (no qualify), or Unreachable to Not registered
* Unreachable or Not registered to Reachable
* Unreachable or Not registered to Registered (no qualify)

First baseline transitions from Unknown are suppressed. Old status-history rows
are not replayed later after recipient or settings changes.

Repeat alert modes can send reminders while an alertable registration state
continues. A recovery transition resets the reminder clock.

Repeat alert modes:

* Never: send only the initial state-change alert.
* Every 5 minutes: repeat every 5 minutes while the registration remains unavailable.
* Hourly: repeat once per hour while unavailable.
* Daily: repeat once per day while unavailable.
* Escalating: uses a Fibonacci-style backoff schedule, starting with shorter reminders and gradually increasing the interval up to daily.

Stored legacy `fibonacci` repeat-mode values are treated as Escalating.

The default debounce delay is 0 seconds, so first alerts are sent immediately
when an alertable problem is detected. Increase this value to reduce noise from
short reloads, restarts, and transient network events.

Storm Threshold limits large batches of alerts generated in the same processing
pass. It reduces email floods from sudden widespread registration changes, but
it is not full correlated-outage detection. The count is per registration, not
per extension, so an extension with several devices can contribute several
alerts. Use 0 to disable.

Watched extensions that have been continuously absent for 30 days are
auto-disabled to stop stale devices from alerting forever. They remain visible
in the Watched Extensions table and are re-enabled automatically if the same
registration returns.

Email sending uses FreePBX/CodeIgniter mail support. Registration Watch does not
use raw PHP `mail()` fallback. A successful local mailer handoff means the
message was accepted by the PBX mailer, not that final external delivery is
guaranteed.

## Security Model

* AJAX commands use a fixed command allowlist.
* AJAX handlers require a module-owned session CSRF token.
* SQL writes and history deletes use prepared statements.
* Asterisk access is read-only in this phase.
* No shell execution is used by the module.
* Alerts are not sent merely from page load.

Granular FreePBX ACL integration is still future work.

## Current Limitations

* Reconciliation is periodic, not event-driven.
* No AMI ContactStatus listener yet.
* No custom probes.
* No maintenance windows yet.
* No webhook or SMS alert delivery yet.
* Short flaps can be missed between reconciliation runs.
* Email delivery depends on the PBX mail sender and relay setup.
* Registration Watch only watches registrations for configured FreePBX PJSIP
  devices (extensions). PJSIP trunks and other non-device PJSIP objects are not
  watched. A live contact is matched to a device by its endpoint identity
  against the FreePBX devices table, so custom or unusual PJSIP objects whose
  contact target does not match a configured device ID will not appear.
* Registration Watch identifies watched entries from the FreePBX extension
  identity and registration/contact details exposed by Asterisk. Multiple
  contacts for the same extension may appear as separate watched entries where
  Registration Watch can distinguish them. Contact URI, source address, and port
  details are shown as diagnostic detail and may change as the device
  re-registers.

## Validation

Useful local checks:

```sh
php -l Registrationwatch.class.php
php -l Job.php
php -l page.registrationwatch.php
php -l install.php
php -l uninstall.php
php -l views/main.php
php -r '$xml = simplexml_load_file("module.xml"); echo $xml ? "module.xml parsed\n" : "module.xml failed\n";'
```

On a real FreePBX/PBXact 17 system:

```sh
fwconsole reload
fwconsole job --list | grep -i registrationwatch
fwconsole job --run=<job_id> --force
```

## Uninstalling

Uninstalling Registration Watch removes the module job and drops
`registrationwatch_*` tables. Back up first if you need Registration Watch data.

```sh
fwconsole ma uninstall registrationwatch --force
rm -rf /var/www/html/admin/modules/registrationwatch
fwconsole chown
fwconsole reload
```

## Licence

GPLv3+. See LICENSE.

## Author

@kierknoby, Kieran Knowles-Byrne // FreePBX UK
