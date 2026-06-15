# Registration Watch for FreePBX 17

Registration Watch (`registrationwatch`) watches SIP/PJSIP registration state in
FreePBX/PBXact 17. It discovers local PJSIP extensions, records registration
state changes, and can send email alerts when watched extensions become
unavailable or recover.

## Compatibility

Use with FreePBX/PBXact 17 only. Do not install on FreePBX/PBXact 16 or below.

## Requirements

* FreePBX/PBXact 17
* PJSIP channel driver
* Existing FreePBX PJSIP extensions/devices
* Asterisk manager command support available to FreePBX
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

`registrationwatch_registrations` stores discovered registered extensions, watch toggles,
admin notes, and the latest status snapshot.

`registrationwatch_status_history` stores transition rows created during
reconciliation.

`registrationwatch_settings` stores simple key/value settings, including alert
configuration, UI show limits, trusted VPN networks, polling interval, and
history pruning policies.

`registrationwatch_alert_history` stores one row per recipient and alert
decision. The unique key `registrationwatch_alert_unique_transition_recipient`
prevents repeated handling of the same transition, alert type, and recipient.

## Alerting

Alerts are generated from reconciliation-created transition rows.

Defaults:

* Alerts disabled
* Recipients empty
* Alert on unreachable enabled
* Alert on not registered enabled
* Alert on recovery enabled
* Debounce seconds: `0`, maximum `86400`
* Repeat suppression seconds: `0`, maximum `86400`

Alertable transitions:

* Reachable or Registered (no qualify) to Unreachable
* Reachable, Registered (no qualify), or Unreachable to Not registered
* Unreachable or Not registered to Reachable
* Unreachable or Not registered to Registered (no qualify)

First baseline transitions from Unknown are suppressed. Old status-history rows
are not replayed later after recipient or settings changes.

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

Repeat alerting beyond the existing repeat suppression behaviour is not part of
the 1.2.0 release.

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
