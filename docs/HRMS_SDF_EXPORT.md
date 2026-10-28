# Independent HRMS SDF export

Implements append export from tbl_rawdata independently of Talenta/FlFlag. No additional DB table or legacy-table column is created. Production writer is NOT enabled or installed by this change. Existing ABSENSI.SDF was not modified during development.

## Layout and current evidence

`03 | 002038 | 20261027 | 1604 | 1` + CRLF = 23 bytes. Terminal 003 becomes 03 (only 0-prefixed three-digit or two-digit terminals allowed); card padded to six digits; original date/time rendered YYYYMMDDHHMM, seconds intentionally omitted. Every physical tap occurrence is retained, even exact identical source tuples. Last digit currently sourced from FcDirection, **provisional**: observed rows matched but FcDirection versus a constant has not been conclusively distinguished. Set HRMS_DIRECTION_CONFIRMED=true only after confirming the receiving system's contract or legacy exporter behavior. It is never sourced from FlFlag.

Evidence showed SDF gained six rows before Talenta dispatch while source FlFlag remained 0. Therefore HRMS export must not depend on Talenta acceptance. `kirimkeHRMS.bat` runs only HRMS; `kirimkeTalenta.bat` is unchanged. A Talenta failure cannot stop the independent HRMS task.

## Configuration

Examples include (not inserted into active .env automatically):

```ini
HRMS_EXPORT_ENABLED=false
HRMS_EXPORT_ON_RECEIVE=false
HRMS_SDF_PATH=C:\ABSENSI_MKM\ABSENSI.SDF
HRMS_EXPORT_FROM=
HRMS_DIRECTION_CONFIRMED=false
```

Set `HRMS_EXPORT_ON_RECEIVE=true` only after the same reviewed baseline/cutover below. Each successfully stored receiver request then runs the guarded append before returning its acknowledgement; `kirimkeHRMS.bat` is not required. A file failure cannot roll back the already committed database row, is recorded in `storage/hrms-on-receive.jsonl`, and must be reconciled before assuming SDF delivery.

Set HRMS_EXPORT_FROM to a reviewed original machine wall-clock cutover timestamp, e.g. `2026-10-27 18:00:00` only if that is the actual agreed boundary. Do not guess a date or use sync time as attendance time. Every run reads all original events from the chosen cutoff (not a rolling window), maximum 100000 rows; exceeding this limit fails explicitly and requires reviewed state rotation. Source retention/deletion/changes are detected and held. This implementation is suitable for a bounded staged cutover, not unlimited history without state rotation.

## First use: shadow file first

1. Keep HRMS_EXPORT_ENABLED=false and set reviewed cutoff. Run `php bin/console.php hrms:preview`. Reads DB only and prints a maximum five formatted sample lines/count; no file write and no Talenta request. Treat output as private employee attendance data.
2. Confirm exact line format/final digit, timezone, leading zeros, duplicate multiplicity with HRMS owner. Compare shadow output against existing writer before replacement.
3. Point HRMS_SDF_PATH to a **new empty shadow file** in an existing directory. Set HRMS_DIRECTION_CONFIRMED=true and HRMS_EXPORT_ENABLED=true for the controlled shadow test; run `php bin/console.php hrms:export` twice. First run appends pending occurrences, second adds zero. Do not point enabled test at the real SDF while the old writer is active.

## Existing-file cutover

Do not start this exporter beside the old writer. The old SDF writer/process is still unidentified. First identify/stop it during an approved cutover, pause source writes briefly if needed, backup SDF + DB + export state, and reconcile that the SDF contains every source event up to the boundary. Counting same-minute rows matters; never deduplicate by formatted text.

For an existing nonempty SDF, ordinary export refuses first startup. Only after reconciling the current source scope as already exported, explicitly initialize:

```bat
php bin\console.php hrms:baseline --confirm=BASELINE_EXISTING_HRMS
```

**Baseline skips all current source rows in scope as already exported**, preserves existing SDF bytes, and creates export counts/state. This is not an automatic reconciliation routine: running it with pending/unexported source data loses those exports. A prior state cannot be overwritten by baseline. Alternatively use a reviewed forward cutoff/new empty target agreed with HRMS; never change target/cutoff merely to escape a state error.

After baseline/cutover, run `kirimkeHRMS.bat` from the deployed new application folder. To preview: `kirimkeHRMS.bat -Preview`. Optional MKM_HRMS_PHP_EXE points at intended PHP (7.4+). Windows service account needs DB SELECT, target file append/read, and writable private app storage. No dependence on the BAT working directory.

## Windows scheduling

Create a **separate** Windows Task Scheduler task for `C:\xampp\htdocs\API_new\kirimkeHRMS.bat`, Start in the same application folder. Choose repetition to match HRMS timeliness (for example every 5 minutes after business approval). Keep task disabled until cutover and test under its actual service account. This feature does not register, enable, replace, or modify any Windows task. Talenta's 08/12/18 tasks remain separate; their four-table integration readiness is unchanged by adding HRMS.

## Tracking and recovery

Private storage/hrms-sdf-<context hash>.json counts each full event tuple occurrence, excluding mutable FlFlag. Exact duplicate source tuples have counts 1,2,3 rather than being collapsed. Original seconds distinguish taps even if rendered SDF lines match. All source rows are read irrespective of FlFlag. No UPDATE/DELETE issued against tbl_rawdata.

Process lock plus file lock prevents concurrent new-app writers. Tail hash/byte offset detects changes by the old writer or HRMS consumer. Only a **single writer** is supported; an external writer ignoring locks can race, so external-change detection is not permission for parallel production writers. Consumers must not truncate/rotate the target without coordinated state handling.

Before append, a pending payload/offset/count map is atomically saved. If interrupted, next run verifies original prefix and any matching partial suffix, then completes once; mismatch refuses instead of blindly resending. The state must remain persistent with the file. Do not delete state to force retry. On PHP 7.4 fflush is available but fsync is not; sudden OS/power failure durability still needs operational backup/reconciliation. Audit metadata is written to storage/hrms-export.jsonl after export.

Exporter never changes Talenta ledger or FlFlag. Failed file write does not mark HRMS complete. Failed Talenta request does not suppress HRMS. Missing/ambiguous final-digit semantics keep live SDF disabled.

## Verification performed

`tests/hrms-sdf.php` passes 10 checks on actual PHP 7.4.33 and PHP 8.2.12: byte-exact sample, duplicate multiplicity, idempotent repeat, FlFlag independence, partial-write recovery, source mutation refusal, external file modification refusal, nonempty first-start hold, explicit baseline, terminal overflow refusal. Tests use temporary private files only and remove their fixtures. New PHP files lint on 7.4; existing four-table DB and multi-employee identity tests pass. `hrms:export` currently returns disabled. No production task/file/DB/remote API mutation performed.
