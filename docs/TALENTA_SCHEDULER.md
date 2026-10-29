# Scheduled Talenta export: 08:00, 12:00, 18:00 WIB

Machine reception and Talenta submission are independent:

```text
Machine tap → immediate local raw-event storage
                  ↓
08:00 / 12:00 / 18:00 Asia/Jakarta scheduler
                  ↓
validated, mapped, unattempted events → queue → Talenta import
```

The receiver never calls the Talenta import API. The manual **Tarik Absensi Talenta** menu remains a separate GET/read feature.

## Incremental behavior

- At 08:00, a reliable valid clock-in may be sent while the shift is still open and clock-out is not yet available.
- At 12:00, send newly available validated events only. An earlier attempted IN is skipped.
- At 18:00, send the new valid OUT and other eligible unsent events. Do not resend the IN from the morning.
- Actual machine timestamps are preserved, including overnight shift dates. Default lookback covers three previous days plus today, so a night-shift OUT can be considered in the next scheduled run.
- Ambiguous windows, unknown/insufficient partial direction evidence, future taps, incomplete mappings, missing HR policies, and protected/locked/approved attendance remain held.

The normal full-record review/export workflow is unchanged. The separately approved scheduled workflow permits only the provisional/open-shift warning for a partial reliable IN. It does not invent an OUT, infer unknown device direction, or automatically export HR corrections. Approval is restricted to the one configured test NIK.

The existing integration queue and file ledger protect each individual raw event and actual timestamp. Different slots may hold different events from the same attendance date. Concurrent runs are locked, a slot is reserved before sending, and an already attempted slot is not replayed automatically. Previously attempted/uncertain events are never automatically retried. HTTP 2xx still means pending Talenta reconciliation, not confirmed attendance import.

## Current state

Code, scheduler preview, Windows task installer and cron example are prepared. **Automatic submission remains disabled** with `TALENTA_SCHEDULER_ENABLED=false` until explicitly enabled in the deployed environment.

When `INTEGRATION_STORAGE=legacy_app`, the same `talenta:scheduler` command now uses the legacy dispatcher instead of generated integration tables. It scans pending cards and immutable source tuples with deterministic keyset cursors, not OFFSET, so successful `FlFlag` changes cannot create pagination gaps. `TALENTA_SCHEDULE_EMPLOYEE_BATCH_SIZE` (default 25) bounds identity resolution work and `TALENTA_SCHEDULE_ROW_BATCH_SIZE` (default 500) bounds each CSV/row page; these are internal resource controls, not assumed Talenta API limits. `TALENTA_SCHEDULE_MAX_SUBMISSIONS` defaults to 4 and may only be set from 1 through 4 so one run cannot fill more than Talenta's documented fingerprint-import queue capacity. Interactive preview retains its 100-employee/1,000-row safeguards. Each scheduled batch is previewed and hash-checked immediately before submission, and only its exact accepted tuples become `FlFlag=1`.

The reviewed Attendance MKM contract is packaged without credentials so a clean Git deployment can pass the contract gate. A deployment may still provide the older private `config/contract.json` plus matching `storage/postman.json`; both runtime files must exist together and retain their reviewed hash. Partial or changed runtime evidence remains blocked.

Scheduled legacy batches skip only identities that Talenta confirms are unusable for fingerprint import, such as a missing/mismatched barcode, invalid user ID or a non-unique employee result. Skipped source rows remain `FlFlag=0`, are excluded from the CSV and are counted in `records_skipped`/`cards_skipped`; valid employees continue independently. Ambiguous tuples and previously attempted events are held without retry. Rejected batches are failed and transport/unknown outcomes are held for reconciliation. The one evidence-backed exception is HTTP 400 with the exact Talenta error `there is another export or import process going on, please retry later`: that request is recorded as `deferred_retryable`, leaves `FlFlag=0`, stops later submissions in the same run and may be retried by a later run. Historical encrypted ledger entries with that exact response receive the same treatment without deleting or rewriting the audit ledger. Manual interactive sending remains strict and does not skip an invalid selected identity.

`kirimkeTalenta.bat --manual` explicitly runs the same legacy scheduler pipeline outside the 08:00/12:00/18:00 windows. It does not weaken the enabled/live-write/credential/profile gates, preview revalidation, identity skips, ledger protection or exact post-acceptance flag update. The no-argument BAT remains schedule-window-only; `--preview` remains read-only.

No new database migration is needed; scheduled batches use the existing integration queue. The new status panel on the dashboard shows the three WIB slots and whether automatic submission is enabled.

## Review and activation

1. Configure and verify the real test NIK, all card/employee/user/badge mappings, device directions, shift/calendar/grace policies and the actual Talenta CSV profile. Minute-resolution CSV profiles cannot silently discard machine seconds.
2. Confirm the supported CLI runtime (PHP 7.4+), trusted CA/proxy/network access and company scope. Apache GET reads succeeded previously while agent CLI network checks timed out; this is not proof that a Windows Task Scheduler service account can reach Mekari. Test the actual deployment account/runtime with `php bin/check-talenta-read.php` before activating writes.
3. Review candidates without writes:

```powershell
C:\php\php.exe bin/console.php talenta:scheduler --preview
```

Preview requires the configured verified employee and profile contract, but does not send requests, create queue records, or reserve a slot.

In `legacy_app`, preview may perform authenticated employee-information GET requests to validate uncached card identities. It does not call the attendance import endpoint or update `FlFlag`.

4. After completing UAT, set the existing verified write gates deliberately: `DRY_RUN=false`, `ALLOW_PRODUCTION_WRITE=true`, `TALENTA_CSV_PROFILE_CONFIRMED=true`. These alone do not enable the scheduler.
5. Approve the concrete employee/company/configuration profile:

```powershell
C:\php\php.exe bin/console.php talenta:scheduler-approve --confirm="SCHEDULED SEND <configured NIK>"
```

This records a signed profile under private storage and makes no API request. The approval fingerprint binds company, employee mapping, User ID, credentials, CSV contract, shift/terminal/roster/calendar/status configuration and schedule settings. Configuration changes invalidate it and require review again. Keep APP_KEY and persistent storage across deployments.

6. Set `TALENTA_SCHEDULER_ENABLED=true` only when ready to activate. Schedule this command at the three specified WIB times:

```powershell
C:\php\php.exe bin/console.php talenta:scheduler
```

Normal operation additionally checks profile approval, every existing live-write gate, HR protections and duplicate keys. A slot may start within `TALENTA_SCHEDULE_WINDOW_MINUTES=15` minutes of the nominal time (set 1 for a strict one-minute window). Outside those windows it does not send. Missed slots are not sent at arbitrary hours; eligible events are considered at the next slot, within `TALENTA_SCHEDULE_LOOKBACK_DAYS=3` (allowed 1..7).

The generated integration scheduler retains its signed-profile approval. The `legacy_app` scheduler instead requires the already reviewed legacy gates (`TALENTA_LEGACY_RAW_CONFIRMED`, CSV profile confirmation, live-write flags, credentials) and reuses the exact preview hash immediately before submission.

### Windows Task Scheduler

`bin/install-talenta-schedule.ps1` prepares a new task with three daily triggers. It requires the Windows timezone `SE Asia Standard Time`, verifies the PHP path and refuses to overwrite an existing task name. It never changes the timezone or existing legacy tasks.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bin/install-talenta-schedule.ps1
```

The resulting task is **disabled**. Review its service account, PHP path, network access, logging and trigger timezone before enabling it through Task Scheduler. Use a dedicated account and persistent server storage. The script was syntax-checked but was not executed to register a task.

### Linux/cron

`config/talenta-schedule.cron.example` contains a commented template for a cron implementation supporting CRON_TZ. Verify server timezone and paths before installing. On a UTC cron server the corresponding trigger hours are 01:00, 05:00 and 11:00 UTC. The application always evaluates the slots in Asia/Jakarta independently of the host's default timezone.

## Logs and reconciliation

- `storage/scheduler.jsonl`: slot result and counters, without credentials or raw HR payloads.
- `storage/legacy-scheduler.jsonl`: legacy slot result, pending/submitted/flag counters and safe ledger reference, without CSV or raw attendance rows.
- `storage/scheduler-state.json`: attempted-slot reservations. Do not delete to force a retry.
- `storage/scheduler-approval.json`: signed profile fingerprint, no secret values.
- Existing database queue and `storage/ledger.json`: event reservations and API result metadata.

The 29 October production batching deployment, rollback and 28 October pending-row recovery runbook is in `docs/PRODUCTION_BATCH_SCHEDULER_2026-10-29.md`.

If a process stops after reservation or an API outcome is uncertain, inspect and reconcile with Talenta before any manual retry. Do not clear unknown events automatically. Source raw events are never deleted by the scheduler.

`batches_deferred` and `dispatch_stopped` distinguish Talenta queue backpressure from payload rejection. An exact concurrency deferral is safe to resume at the next scheduled or explicitly authorized manual run; arbitrary HTTP 400 responses remain permanently blocked until human reconciliation.

## Validation

```powershell
C:\php\php.exe tests/talenta-scheduler.php
C:\php\php.exe tests/talenta-scheduler-db.php
```

23 scheduler logic checks and 6 actual local database checks cover all three times, out-of-window/UTC behavior, partial reliable IN, ambiguous/unknown holds, original timestamp identity, disabled defaults, signed-profile validation/tamper protection, separate morning-IN/evening-OUT batches, unique event claims and duplicate prevention. Database fixtures roll back; no import POST is used by these tests.
