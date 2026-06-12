# EndPoint Monitor for FreePBX 17

## Current Release

Minor release: 1.1.0, released at 21:00 on 12 June 2026.

## Version Update

Minor release from 1.0.1 to 1.1.0 on 12 June 2026 by kierknoby

Adds configurable Status History and Alert History pruning with Never, Hourly, Daily, Monthly, and Yearly policies, adds confirmed single-row history deletion, makes initial page rendering and endpoint map auto-refresh read-only, adds module-owned session CSRF protection for AJAX, caps alert timing fields to 0-86400 seconds, improves pruning Apply/Active UI, improves responsive history controls, adds friendlier history labels, corrects endpoint address display by deriving Device IP and Device Port from the SIP Contact URI, and removes misleading/noisy Asterisk source details from default endpoint and alert output.

Patch release from 1.0.0 to 1.0.1 on 11 June 2026 by kierknoby

Fixes alert send reservation to prevent duplicate normal alert emails, prevents
duplicate Test Email click binding, removes duplicate notes autosave handling,
maps internal source labels to Asterisk, updates FreePBX/PBXact 17-only release
wording, and includes minor alert email copy cleanup.

## Compatibility

Use with FreePBX/PBXact 17 only. DO NOT install on FreePBX/PBXact 16 and below.

## Overview

EndPoint Monitor discovers FreePBX PJSIP extensions, lets administrators enable
or disable monitoring per endpoint, and shows the latest contact status after
manual or scheduled reconciliation.

The module provides endpoint discovery, current status visibility, transition
history, and email alert attempts from reconciliation-created state changes.
Scheduled background reconciliation is handled by the FreePBX Job system. The
module does not install a daemon, systemd unit, AMI event listener, or custom
probe service.

## Requirements

* FreePBX/PBXact 17 only
* PJSIP channel driver
* Asterisk manager command support available to FreePBX
* Existing FreePBX PJSIP extensions/devices
* FreePBX Job runner enabled for scheduled background checks
* FreePBX mail support configured if alert email delivery is required

## Installation

Pick whichever path fits. The module is currently unsigned and community-supported.

### Option 1: Existing module directory

Place the `endpointmonitor` directory in `/var/www/html/admin/modules/`, then:

```sh
fwconsole ma install endpointmonitor
fwconsole chown
fwconsole reload
```

### Option 2: Developer install from GitHub

```sh
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/endpointmonitor.git endpointmonitor
fwconsole ma installlocal endpointmonitor
fwconsole chown
fwconsole reload
cd
```

### Option 3: Developer install from a local copy

From inside the module directory:

```sh
cd /var/www/html/admin/modules/endpointmonitor
fwconsole ma installlocal
fwconsole chown
fwconsole reload
cd
```

Use `installlocal` when installing from an unpacked local module directory.

The module appears under **Admin > EndPoint Monitor**.

## Updating

Do not uninstall the module when updating. Uninstalling removes the module tables.

A normal update keeps the existing EndPoint Monitor database tables, settings, monitored endpoints, status history, and alert history.

### Check the installed version

```sh
fwconsole ma list | grep -i endpointmonitor
```

Expected output includes the installed version and enabled state, for example:

```text
| endpointmonitor     | 1.1.0      | Enabled | GPLv3+      | Unsigned  |
```

You can also check the module file directly:

```sh
grep -n "<version>" /var/www/html/admin/modules/endpointmonitor/module.xml
```

### Option 1: Existing module directory

Place the updated `endpointmonitor` directory in `/var/www/html/admin/modules/`, then:

```sh
fwconsole ma install endpointmonitor
fwconsole chown
fwconsole reload
```

### Option 2: Developer update from GitHub

From inside the existing GitHub-installed module directory:

```sh
cd /var/www/html/admin/modules/endpointmonitor
git config --global --add safe.directory /var/www/html/admin/modules/endpointmonitor
git pull origin main
fwconsole ma installlocal endpointmonitor
fwconsole chown
fwconsole reload
cd
```

### Option 3: Developer update from a local copy

From inside the updated module directory:

```sh
cd /var/www/html/admin/modules/endpointmonitor
fwconsole ma installlocal
fwconsole chown
fwconsole reload
cd
```

Use `installlocal` when updating from an unpacked local module directory.

After updating, check the installed version again:

```sh
fwconsole ma list | grep -i endpointmonitor
```

Then open **Admin > EndPoint Monitor** and confirm existing endpoints, settings, and history are still present.

## Architecture

EndPoint Monitor has four current paths:

1. **Discovery path** reads local FreePBX PJSIP extension/device data and keeps
   `endpointmonitor_endpoints` aligned with discovered endpoints.
2. **Reconciliation path** runs a read-only Asterisk manager command for
   current PJSIP contact state, updates the latest snapshot, and writes
   transition rows when state changes.
3. **Alert path** classifies reconciliation-created transitions, applies
   debounce and repeat suppression, sends email through FreePBX mail support,
   and records alert history.
4. **Admin page path** renders the endpoint status map, monitored endpoint
   toggles, alert settings, status history, and alert history.

Scheduled reconciliation is registered through the FreePBX Job system. FreePBX
runs its central job runner from cron, so this module does not need its own
daemon or systemd service.

Future AMI ContactStatus ingestion should write to the same history table rather
than creating a separate event model.

## Background Monitoring

EndPoint Monitor registers a FreePBX job named:

```text
endpointmonitor :: monitor
```

The job calls the module's background monitor method and runs reconciliation
server-side, even when the admin page is not open.

The background job currently:

* Reads alert and polling settings.
* Skips cleanly if polling is disabled.
* Runs reconciliation when polling is enabled, even if alerts are disabled.
* Records state transitions.
* Processes eligible alert decisions.
* Writes status output to the FreePBX job runner.

The default FreePBX job cadence is once per minute. The admin page may still
refresh visually more frequently while open, but true background monitoring is
handled by the FreePBX Job system.

Useful checks:

```sh
fwconsole job --list | grep -i endpointmonitor
fwconsole job --run=<job_id> --force
```

## Security Model

* AJAX commands use a fixed command allowlist.
* Current AJAX commands are `refresh`, `setenabled`, `savenotes`,
  `saveshowlimit`, `savealerts`, `savetopology`, `testemail`, `gettopology`,
  `saveprunepolicy`, `deletestatushistoryrow`, and `deletealerthistoryrow`.
* AJAX handlers require a module-owned session CSRF token.
* SQL writes and history deletes use prepared statements.
* Asterisk access is read-only in this phase.
* No shell execution is used by the module.
* Email is sent only through FreePBX mail support.
* Alerts are not sent merely from page load.

## Hardening Notes

Access control currently relies on FreePBX authenticated admin context plus CSRF
token validation. Granular ACL support is a future enhancement.

**TODO:** Implement FreePBX module-level ACL integration:

* Add ACL checks via `\FreePBX\Acl` when available.
* Define granular permissions, such as `endpointmonitor/manage` and
  `endpointmonitor/view`.
* Restrict AJAX handlers based on permissions rather than presence checks.

Logging improvements:

* Reconciliation and email send failures are logged to FreePBX system logs.
* Parser diagnostics log once per refresh cycle with failure count and sample
  line.
* Stack traces are never exposed in the UI; users see generic error messages.

Backup/restore:

* Settings and endpoint monitoring configuration are backed up and restored via
  upsert logic.
* Status and alert history are currently **not** backed up to avoid duplicating
  operational records during restore.
* Status and alert history are volatile audit-trail records and should not be
  carried forward on restore by default.
* History export can be added later if needed.

Admin UI:

* Monitored endpoints can have short admin notes of up to 48 characters, saved inline with a timestamp.
* Show selection is saved as a module setting and applies to the map and history tables.
* History pruning policies default to Never. Hourly, Daily, Monthly, and Yearly
  pruning, plus single-row history deletion, permanently delete matching history
  rows after explicit administrator confirmation.
* Endpoint Status Map shows a limited tile view by default, with Show options for
  6, 30, 60, 120, and All.
* Endpoint detail displays device IP, device port, device, version, contact
  expiry, qualify frequency, and latency where available.
* Temporary action messages appear as fading overlay messages so they remain visible on long pages.
* Warning banners appear where alert configuration cannot support delivery.

## Discovery Model

Discovery is PJSIP-extension focused.

* Primary source: FreePBX `devices` rows with `tech = 'pjsip'`.
* Fallback source: FreePBX `users` rows with matching PJSIP endpoint objects.
* A matching PJSIP endpoint object is required.
* Trunk-only PJSIP objects are intentionally excluded.
* Virtual/non-PJSIP extensions are intentionally excluded.

## Status Model

Current status is a snapshot of the most recent reconciliation.

Current states:

* Reachable
* Unreachable
* Registered (No Qualify)
* Not Registered
* Unknown

`Registered (No Qualify)` means Asterisk has a contact but qualify/RTT data is
not available. The UI shows RTT as unavailable rather than treating the endpoint
as unknown.

`Removed` is not used as a current state. When an endpoint previously had a
contact or registered/reachable state and reconciliation finds no contact, the
current state becomes `Not Registered` and the transition reason is shown as
Contact removed.

## Status History

Status History is transition-based. It stores state changes, not every refresh.
The admin page shows the most recent transitions.

Reconciliation writes history rows with:

* `source = Asterisk`
* Contact removed when a previously contacted/registered endpoint becomes
  `Not Registered`
* Status changed for other state changes

Until AMI ContactStatus ingestion exists, short flaps can still be missed
between reconciliation runs.

## Email Alerting

Alerts are generated from transition rows created by reconciliation.

Defaults:

* Alerts disabled
* Recipients empty
* Alert on unreachable enabled
* Alert on not registered enabled
* Alert on recovery enabled
* Debounce seconds: `0`, maximum `86400`
* Repeat suppression seconds: `0`, maximum `86400`

Alertable transitions:

* Reachable or Registered (No Qualify) to Unreachable
* Reachable, Registered (No Qualify), or Unreachable to Not Registered
* Unreachable or Not Registered to Reachable
* Unreachable or Not Registered to Registered (No Qualify)

First baseline transitions from Unknown are suppressed. If an endpoint recovers
to Registered (No Qualify), the email notes that qualify is disabled and RTT is
unavailable.

Alert emails include a reminder that email delivery can be delayed and that
current status should be checked in the FreePBX module.

Alert decisions are recorded per recipient. The module prevents repeated
handling of the same transition and recipient once an alert-history row exists.
This avoids repeated sends when manual refresh and scheduled reconciliation
touch the same transition.

The alert-history insert uses duplicate-tolerant database insertion so a race
between two workers does not create duplicate rows or fail noisily.

FreePBX mailer compatibility must be tested on a real FreePBX 17 system. The
module intentionally does not use raw PHP `mail()` fallback.

Email sending uses FreePBX/CodeIgniter mail support. The module attempts to use
the configured FreePBX notification sender. If no usable sender is available,
the alert attempt should fail safely and record the error rather than guessing a
sender domain.
The FreePBX Advanced Settings Email "From:" Address should be configured before
using alert emails or Test Email.

A successful local mailer handoff means the message was accepted by the local
mailer. It does not guarantee external delivery. Final delivery still depends on
the PBX mail configuration, relay rules, SPF/DKIM alignment, and the recipient
mail system.

## Database Tables

`endpointmonitor_endpoints` stores discovered endpoints, monitoring toggles, and
the latest status snapshot.

`endpointmonitor_status_history` stores transition rows:

```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
extension VARCHAR(80) NOT NULL
from_state VARCHAR(40) NULL
to_state VARCHAR(40) NOT NULL
source VARCHAR(40) NOT NULL
reason VARCHAR(80) NULL
contact_uri TEXT NULL
latency_ms DECIMAL(10,3) NULL
created_at DATETIME NOT NULL
```

Indexes:

* `extension`
* `created_at`
* `to_state`
* `source`

`endpointmonitor_settings` stores simple key/value settings, including alert
configuration, UI Show limits, and history prune policies. The history prune
settings are `status_history_prune_policy` and `alert_history_prune_policy`,
both default to `never`, and valid policies are `hourly`, `daily`, `monthly`,
`yearly`, and `never`.

`endpointmonitor_alert_history` stores one row per recipient and alert decision:

```sql
id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
extension VARCHAR(80) NOT NULL
history_id BIGINT UNSIGNED NULL
alert_type VARCHAR(40) NOT NULL
status VARCHAR(40) NOT NULL
recipient VARCHAR(255) NOT NULL
subject VARCHAR(255) NOT NULL
message TEXT NULL
sent_at DATETIME NOT NULL
result VARCHAR(40) NOT NULL
error TEXT NULL
```

Duplicate guard:

```sql
UNIQUE KEY endpointmonitor_alert_unique_transition_recipient
(history_id, alert_type, recipient(191))
```

Indexes:

* `extension`
* `history_id`
* `alert_type`
* `recipient`
* `sent_at`
* `result`

## Contact Refresh Notes

The current implementation parses `pjsip show contacts` through Asterisk manager
command support. That CLI parsing is a short-term implementation detail. Future
releases should move toward structured AMI/PJSIP data where practical. Parsing
human-readable CLI output should be treated as technical debt rather than a
permanent architecture decision.

## Current Limitations

* Reconciliation is periodic, not event-driven.
* No AMI ContactStatus listener yet.
* No custom probes.
* No maintenance windows yet.
* No webhook or SMS alert delivery yet.
* Short flaps can be missed between reconciliation runs.
* Email delivery depends on the PBX's configured mail sender and relay setup.

## Future Design Notes

* Keep MVP PJSIP only.
* Continue monitoring selected endpoints/extensions.
* Add AMI ContactStatus ingestion when the snapshot/history/alert behaviour is
  stable.
* Add maintenance windows.
* Add optional webhooks/SMS later.
* Consider history export controls.
* Consider structured data sources to reduce CLI parser dependency.

## AI Disclosure

This module has been developed with AI assistance for code generation, review,
testing, and documentation. Changes should still be reviewed, tested, and
accepted by a human maintainer before deployment.

## Tests

Useful checks:

```sh
php -l Endpointmonitor.class.php
php -l Job.php
php -l page.endpointmonitor.php
php -l install.php
php -l uninstall.php
php -l views/main.php
php -d xdebug.mode=off -r '$xml = simplexml_load_file("module.xml"); echo $xml ? "module.xml parsed\n" : "module.xml failed\n";'
fwconsole job --list | grep -i endpointmonitor
```

On a real FreePBX/PBXact 17 system:

```sh
fwconsole reload
fwconsole job --list | grep -i endpointmonitor
fwconsole job --run=<job_id> --force
```

Expected job output:

```text
Running endpointmonitor :: monitor ...
EndPoint Monitor background job completed.
Done with monitor
```

## Uninstalling EndPoint Monitor

```sh
cd /var/www/html/admin/modules

fwconsole ma uninstall endpointmonitor --force
rm -rf /var/www/html/admin/modules/endpointmonitor

fwconsole chown
fwconsole reload

cd
```

Verify it has gone:

```sh
fwconsole ma list | grep -i endpointmonitor || echo "endpointmonitor removed"
ls -ld /var/www/html/admin/modules/endpointmonitor 2>/dev/null || echo "endpointmonitor directory removed"
```

## Notes

EndPoint Monitor currently uses reconciliation as the source of truth for status
history and alerting. Do not add custom probes or a separate event model until
the existing snapshot/history/alert behaviour has been reviewed on a real
FreePBX 17 system.

## Licence

GPLv3+. See LICENSE.

## Author

@kierknoby, Kieran Byrne // FreePBX UK
