# Read-only audit — 27 October 2026

Audit captured before compatibility refactoring. No production connection, DDL, FlFlag update, or Talenta request performed. Evidence: repository SQL/repository/migration/test inspection; read-only information_schema query against configured local database. User-provided SHOW CREATE TABLE is the production schema reference, not a live production inspection.

## Actual environment versus requested environment

Current `.env` uses MySQL `mkm_attendance_local`, not PostgreSQL. Both installed PHP executables expose pdo_mysql but **no pdo_pgsql**. Local metadata contains the 17 generated integration tables below, no legacy raw table. PostgreSQL connection cannot yet be verified. Do not claim it has been tested. PHP 8.2 remains supported per the user's earlier explicit instruction; no runtime upgrade performed.

## Dependency matrix

| Current Table | Used By | Business Purpose / dependency | Legacy Equivalent | Action |
|---|---|---|---|---|
| attendance_raw_events | AttendanceRepository, receiver, inspector, processor snapshots | Immutable raw payload, event hash, numeric ID; referenced by queue_events and attendance_sources | tbl_rawdata, but no ID/hash/payload | REUSE_LEGACY for new legacy mode; retain old table during staged transition |
| attendances | AttendanceEngineRepository, processor, API export validation, processed-attendance UI | Unique employee/date calculation, IN/OUT, metrics, source hash, HR protections | None; tbl_absen is not equivalent | KEEP_ADDITIONAL in separate integration store when persisted processing is used |
| employee_mappings | AttendanceRepository, processor, scheduler, API identity check | Card/NIK/user/badge identity; shift reference | None | KEEP_ADDITIONAL, required verified identity, could use reviewed config for single-user preview |
| integration_queue | AttendanceRepository, TalentaApiService, scheduler, queue.php | Batch state/reservation, review snapshot, reconciliation | FlFlag only covers a binary state, not unknown outcomes | KEEP_ADDITIONAL, required durable dispatch tracking outside legacy DB |
| queue_events | AttendanceRepository, scheduler | Source-event ownership; FK to integration_queue and attendance_raw_events | None | KEEP_ADDITIONAL, redesign source reference before legacy dispatch |
| integration_settings | IntegrationSettings, settings.php, Mekari credentials | Encrypted credentials | tbl_config proposed, not an original legacy table | MERGE_INTO_EXISTING only for previous explicit config mode; no automatic migration/copy |
| integration_settings_audit | IntegrationSettings | Credential metadata history | tbl_log cannot represent revision/fields reliably | KEEP_ADDITIONAL optional store; existing legacy_config mode embeds credential-only audit in tbl_config |
| schema_version | IntegrationMigrations, AttendanceRepository | Migration ownership/checksum guard | None | KEEP_ADDITIONAL for integration store only; never require it on source DB |
| shifts | AttendanceShiftResolver, processor, AttendanceEngineRepository | Shift windows, overnight rules, grace/rounding policy | None | KEEP_ADDITIONAL when shift engine used |
| terminals | AttendanceRepository, processor | Explicit terminal direction mapping | FcTermNo identifies terminal, not direction semantics | KEEP_ADDITIONAL or reviewed config for preview |
| attendance_shift_assignments | AttendanceEngineRepository, shift resolver | Dated employee roster, priority | None | KEEP_ADDITIONAL for roster-based calculation |
| attendance_work_calendar | AttendanceEngineRepository, WorkCalendarService | Holiday/weekly-off/working-day rules | None | KEEP_ADDITIONAL for calendar calculation |
| attendance_statuses | AttendanceEngineRepository, AttendanceStatusService | Master conditions, final status priority | None | KEEP_ADDITIONAL for status calculation |
| attendance_requests | AttendanceEngineRepository, processor | Approved HR overrides and export protection | None | REQUIRES_BUSINESS_DECISION: integrate authoritative HR approvals or keep current protection |
| attendance_period_locks | AttendanceEngineRepository, processor | Prevent changes to locked HR periods | None | REQUIRES_BUSINESS_DECISION: authoritative HR locking source |
| attendance_sources | AttendanceEngineRepository | Links derived attendance to original events, uniqueness | None | KEEP_ADDITIONAL, redesign reference before legacy persistence |
| attendance_processing_errors | AttendanceEngineRepository, processing command | Operational calculation failures | tbl_log lacks structured context | KEEP_ADDITIONAL optional; structured private log alternative |

No table is automatically deleted. Old attendance_raw_events becomes DEPRECATE only after an approved cutover and source reconciliation, not during this audit. No unrelated roster, queue, or HR records should be packed into tbl_config.

## SQL and module inventory

- `Database.php`: environment DSN switch, PDO role credentials, read-only sessions, metadata, generic mapped source query. Existing generic source query expects one timestamp and cannot consume FdDate + FcTime directly.
- `AttendanceRepository.php`: raw insert/dedup, recent/count/snapshot, shift/card/terminal configuration, queue/reservation/finish/reconciliation, scheduled ownership. Uses generated table names and translates some PostgreSQL SQL to MySQL. Not a legacy adapter.
- `AttendanceEngineRepository.php`: dated HR context, roster/calendar/status configuration, transactional calculated records and source ownership. Uses IntegrationMigrations table qualification.
- `IntegrationSettings.php` and `CredentialCipher.php`: AES-GCM encrypted settings with optimistic revision; APP_KEY in .env. Current legacy_config table alias only supports MySQL.
- `MachineRequestParser.php`, `MachineReceiverService.php`, `ReceiverHttp.php`, `public/API/index.php`: observed-v1 parser, allowlist, validation, audit, store acknowledgement; receiver never sends Talenta.
- `AttendanceProcessor.php`, `AttendanceShiftResolver.php`, `WorkCalendarService.php`, `AttendanceStatusService.php`: shared pure shift-aware calculation, overnight, duplicates, missing punches, protection. No requirement that tbl_absen contain these calculations.
- `Mekari.php`, `TalentaApiService.php`, `TalentaAttendanceReader.php`: Postman-verified multipart/HMAC, mapped CSV, durable file ledger before outbound, queue revalidation; independent read-only remote report. Existing 2xx classification means pending reconciliation, not final import proof.
- `TalentaScheduledExport.php`, `bin/console.php`, kirimkeTalenta.bat, run-talenta.ps1: scheduler at 08/12/18 WIB, signed approval and guards; Windows task template disabled. Production task not inspected/modified.
- UI: home/attendance use raw counts/recent/queue; technical.php uses machine-dashboard, processed-attendance, scheduler-status; queue.php revalidates CSV; settings.php writes only credentials on explicit POST. No dispatch from opening dashboard.
- Migrations 001/002/003: generated integration schema, credential table/audit, then engine/shift-policy extension. Existing SQL must not be reapplied to legacy source. LegacyConfigMigration creates tbl_config only under its old opt-in mode; this is incompatible with latest **no production DDL** instruction and must not run in new source-read mode.
- Tests: run/machine/attendance-engine pure calculations; database/attendance-engine-db/settings/scheduler-db transactional local fixtures; legacy-config isolated DB DDL; trial-simulation card restrictions; HTTP suites use local operator routes. Never run DB fixture tests against production.

## Key correctness gaps

1. Legacy six columns have no PK/unique constraint. Identical event-field tuples can be distinct physical events. A hash identifies a value group, not a unique row. Timestamp-only dedup or UPDATE by timestamp is unsafe. Preserve all rows and expose ambiguity. Exact duplicate receiver delivery cannot be proven against an identical pre-existing tuple without an external durable receipt; hold rather than silently merge.
2. Protocol observed direction is two digits (`10`), legacy FcDirection is varchar(1), shown as `1`. Do not truncate or infer conversion. A reviewed explicit mapping is required before local legacy inserts.
3. FlFlag=0/1 meaning comes from user. Null/other values are not pending automatically. No FlFlag writes during compatibility testing. Dispatch must be staged until a durable occurrence identity and acknowledgement policy are approved.
4. Source table stores local date/time without timezone; interpret in configured Asia/Jakarta, preserve seconds. Never replace with send time.
5. Existing queue/source FKs require generated numeric raw IDs. Do not synthesize stable IDs by row_number, ctid, or hash and pretend FK compatibility. A separate occurrence/receipt registry is needed before persisted legacy processing/dispatch.

## Proposed architecture and stages

Source DB: only unchanged tbl_rawdata, tbl_absen, tbl_log. Existing-production mode read-only, no DDL. Local PostgreSQL copies preserve names and quoted mixed-case columns. Config: .env or explicitly provisioned tbl_config; no creation during production audit. Additional required integration store: verified identity/shift/calendar policy, processed attendances/source registry where calculation persistence is needed, durable dispatch attempts/source ownership/reconciliation. Optional processing error table and credential audit table can use private structured logs. Retain all currently used HR protection data pending business decision.

Stage 1 audit (this report) → Stage 2 legacy read adapter + isolated local receiver fixture → Stage 3 verified source occurrence identity + separate integration store/adapter for existing processor → Stage 4 shadow reconciliation + explicit one-user UAT → Stage 5 approved production dispatch/FlFlag writes. No automatic data migration or deletion. Existing integration mode stays operational while new source-only mode is constrained. This report does not authorize production schema changes.
