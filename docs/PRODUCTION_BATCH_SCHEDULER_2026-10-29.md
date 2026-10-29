# Production batch scheduler fix - 29 October 2026

## Root cause

The legacy scheduler reused the interactive preview path. A full day was loaded as one plan, so the manual limits of 100 unique cards and 1,000 raw rows blocked the 12:00/18:00 production runs before any Talenta submission or `FlFlag` update. The scheduler also stopped after the first failed plan.

## Production behavior after the fix

- Interactive/manual web preview keeps the 100-employee and 1,000-row limits.
- The scheduled and explicit `--manual` scheduler paths discover `FlFlag=0` cards in deterministic card pages and immutable tuple pages.
- Keyset cursors are used instead of OFFSET. Updating successful rows to `FlFlag=1` cannot shift later records out of the scan.
- Default internal resource bounds are 25 employees and 500 tuple groups per batch. They are configurable with `TALENTA_SCHEDULE_EMPLOYEE_BATCH_SIZE` and `TALENTA_SCHEDULE_ROW_BATCH_SIZE`; they are not claims about Talenta API limits.
- Identity cache entries are reused. A 60-second identity budget still holds the current batch; identities already validated before the hold remain cached, so a later run resumes safely.
- Invalid/unverified Talenta identities are excluded and remain `FlFlag=0`. Ambiguous identical source tuples and ledger-attempted events are held for reconciliation.
- Every send is re-previewed and hash-checked. The durable ledger reservation still occurs before the network request.
- Explicit failure bodies override HTTP 2xx. Rejected and transport-unknown batches do not update flags. A 2xx response without an explicit failure remains `submitted_pending_reconciliation`, not proof that Talenta finished applying attendance.
- A rejected/held batch does not stop later independent batches. Final status distinguishes `completed`, `completed_with_skips`, `partially_completed`, `held`, and `failed`.

No schema migration, table reset, raw-data rewrite, Task Scheduler time change, PHP upgrade, or Talenta endpoint change is required.

## Safe deployment

Deploy between the 08:00, 12:00 and 18:00 WIB task windows. Keep the active `.env`, `APP_KEY`, `storage/ledger.json`, scheduler/dispatch logs and database together.

```powershell
Set-Location C:\xampp\htdocs\API

Copy-Item .env "storage\.env-before-batch-fix-$((Get-Date).ToString('yyyyMMdd-HHmmss')).backup"
Copy-Item storage\ledger.json "storage\ledger-before-batch-fix-$((Get-Date).ToString('yyyyMMdd-HHmmss')).json" -ErrorAction SilentlyContinue

& "C:\Program Files\Git\cmd\git.exe" `
  -c safe.directory=C:/xampp/htdocs/API `
  pull --ff-only origin main

& C:\xampp\php\php.exe -l app\LegacyAttendanceRepository.php
& C:\xampp\php\php.exe -l app\LegacyManualSend.php
& C:\xampp\php\php.exe -l app\LegacyTalentaScheduledExport.php
& C:\xampp\php\php.exe tests\legacy-talenta-scheduler.php
& C:\xampp\php\php.exe tests\legacy-scheduler-pagination.php
& C:\xampp\php\php.exe tests\import-response-reference.php

.\kirimkeTalenta.bat --preview
```

Preview performs no attendance import and no flag update. It may perform authenticated employee-information GET requests for uncached cards. Review the structured counters before allowing the next scheduled task.

## Structured result fields

The private scheduler JSONL and console output include counts only, not credentials, tokens, employee names or card identifiers:

- `total_pending_records`
- `total_unique_employees`
- `batches_created`
- `batches_submitted`
- `batches_failed`
- `batches_held`
- `flags_updated`
- `records_skipped`
- `records_held`
- `errors`

A run with pending input but zero submitted batches is never reported as completed.

## Rollback

There is no database migration to reverse. Revert the deployment commit in Git and pull the revert on the server, or restore only the backed-up application files. Do not reset/truncate tables and do not restore an older ledger over the current ledger.

```powershell
# Run in a controlled repository clone, then push the generated revert commit.
git revert <batch-fix-commit>
git push origin main

# On the server:
Set-Location C:\xampp\htdocs\API
& "C:\Program Files\Git\cmd\git.exe" -c safe.directory=C:/xampp/htdocs/API pull --ff-only origin main
```

If any batch was attempted after deployment, preserve the current `storage/ledger.json` through rollback. An older ledger can forget an uncertain/accepted attempt and enable a duplicate Talenta submission.

## Safe recovery for 28 October 2026 pending rows

1. Preserve the database and current ledger before recovery.
2. Read counts only; do not update flags manually:

```sql
SELECT FlFlag, COUNT(*) AS records
FROM mkm_ssmdb.tbl_rawdata
WHERE FdDate = '2026-10-28'
GROUP BY FlFlag
ORDER BY FlFlag;
```

3. Deploy the fix and run `kirimkeTalenta.bat --preview` while 28 October remains inside the configured lookback.
4. Review `total_pending_records`, `batches_created`, `records_skipped`, `records_held`, and `errors`.
5. Let the next normal 08:00/12:00/18:00 task run, or use `kirimkeTalenta.bat --manual` once only after explicit production authorization.
6. For `partially_completed`, verify the returned ledger references and Talenta attendance before any reconciliation. Successful exact tuples are already `FlFlag=1`; rejected or held tuples remain `0`.
7. For an unknown transport outcome or accepted request whose flag update failed, do not retry and do not clear the ledger. Reconcile the request in Talenta first.

No live production submission is part of the automated validation for this change.
