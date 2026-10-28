# Local cleanup preflight — 27 October 2026

Status: **STOPPED before any destructive operation**, per requirement 9. This is a read-only preflight, not a completed four-table migration.

Verified by `php bin/audit-local-cleanup.php`:

- Driver: mysql; actual SELECT DATABASE(): mkm_attendance_local; configured host restricted to localhost/loopback.
- Active application mode: integration.
- No connection to mkm_ssmdb was made for this audit.
- 17 existing tables. Required tbl_rawdata/tbl_absen/tbl_log/tbl_config are all absent.

## Non-empty tables

| Table | Row count | Why cleanup must stop |
|---|---:|---|
| attendance_raw_events | 3 | Raw events not migrated to tbl_rawdata; direction conversion unresolved |
| integration_settings | 1 | Existing encrypted credential profile; preserve APP_KEY and ciphertext |
| integration_settings_audit | 5 | Credential audit history not migrated |
| attendance_statuses | 9 | Status master still used by existing processor |
| schema_version | 3 | Current schema ownership/checksums still required by active repositories |

The other 12 tables are empty, but still referenced by current integration mode. Empty is not equivalent to obsolete.

## Active dependency blockers

- AttendanceRepository::assertInstalled/store/recent/snapshot/queue still query schema_version, attendance_raw_events, employee_mappings, shifts, terminals, integration_queue and queue_events.
- IntegrationSettings still reads integration_settings and writes integration_settings_audit in active integration mode; settings.php and credential resolution depend on it.
- AttendanceEngineRepository still depends on the generated calculation/HR context tables and source IDs.
- Legacy read adapter is available, but is not a replacement for all persisted engine/queue behavior. The requested four-table architecture refactor is not complete.
- Existing protocol direction is two characters; target legacy FcDirection is one. Do not truncate during migration. Need explicit verified mapping.

## Operations not performed

No CREATE TABLE, DROP TABLE, DROP DATABASE, UPDATE, foreign-key-disable, production access, Talenta request, or schema migration. No backup was produced in this stopped preflight; a verified recoverable backup is still mandatory before any later mutation. Active .env/APP_KEY were not altered. `bin/audit-local-cleanup.php` only executes SELECTs in a read-only transaction and closes it.

## Required sequence before retry

Finish active four-table adapters (including durable dispatch tracking without extra tables), audit data-preserving migration rules and direction mapping; produce and verify full local backup with restore proof; create/verify four approved tables; migrate and reconcile raw events, credentials/audit and any still-required policy data; switch active UI/receiver/worker/credentials off obsolete table dependencies; run local MySQL application tests. Only then construct a fixed allowlist DROP script using verified FK dependency order, scoped to loopback mysql mkm_attendance_local, without disabling FK checks. Preserve four required tables and refuse unexpected or nonmigrated data.

Do not run old full-schema migrations or an empty-table cleanup shortcut to force the expected table count. Repeat the read-only preflight after refactoring and migration.
