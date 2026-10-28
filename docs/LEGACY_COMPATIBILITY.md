# Schema compatibility — staged delivery

Latest instruction supersedes the previous tbl_config-install deployment: **no production table creation or record changes during audit/testing**. `.env.example` now defaults to legacy_readonly. Existing local `.env` and encrypted credential values were not changed. `.env.config-only.example` preserves the older explicitly opted-in config-table setup, but do not use it for this audit.

## Production MySQL/MariaDB source (read only)

Use a separate application folder. Provision a company-owned SELECT-only DB account through normal DBA administration. Do not use root. Copy `.env.legacy-readonly.example` to the new folder's `.env`, then fill its database credentials locally. On the same server use DB_HOST=127.0.0.1; only use 172.17.210.228 when that is the actual DB host reachable from this application. No production connection was attempted here.

```ini
INTEGRATION_STORAGE=legacy_readonly
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mkm_ssmdb
DB_USERNAME=<SELECT-only account>
DB_PASSWORD=<fill on server>
DRY_RUN=true
ALLOW_PRODUCTION_WRITE=false
MACHINE_RECEIVER_ENABLED=false
TALENTA_SCHEDULER_ENABLED=false
```

Do **not** run migrate/seed. These commands now refuse in legacy_readonly and legacy_local modes. Every PDO purpose in legacy_readonly also sets its session read-only. settings.php refuses config writes; credentials for optional GET Talenta report come only from .env. Live dispatch readiness is false regardless of other write flags.

Read-only checks from the application folder:

```bat
C:\xampp\php\php.exe bin\audit-runtime.php
C:\xampp\php\php.exe bin\console.php inspect
C:\xampp\php\php.exe bin\console.php legacy:preview 2026-10-25 2026-10-27 2120
```

inspect writes a private metadata file locally, not database rows. Preview returns employee attendance data: run privately, never publish its output. Home and Data Absensi render the legacy source preview with exact column names, date/card filtering, and ambiguity indication. Other integration-engine screens still belong to the separate generated integration schema and are not a production legacy dispatch UI. Local operator access restriction remains.

## Local PostgreSQL legacy copy

Install/enable pdo_pgsql in the intended PHP runtime and provide a working local PostgreSQL service. Neither is available in the audited environment. Create an isolated local `mkm_attendance_local` database through DBA tooling. Copy `.env.legacy-pgsql.example` to the **local copy** application's `.env` (back up existing .env first). Set postgres credentials there.

The supplied `migrations/local_legacy.pgsql.sql` is an **explicit local fixture script**, not an automatic app migration. On a clean isolated local database only:

```bat
psql -h 127.0.0.1 -p 5432 -U postgres -d mkm_attendance_local -v ON_ERROR_STOP=1 -f migrations/local_legacy.pgsql.sql
php tests/legacy-postgres.php
```

Never run it against production or an existing populated schema. Names are tbl_rawdata/tbl_absen/tbl_log; PostgreSQL mixed-case columns are quoted to preserve FdDate etc. tinyint becomes SMALLINT, datetime becomes timestamp without time zone; no PK/unique is invented. Existing generated integration SQL/checksums are unchanged.

For an explicit local receiver test only, set LEGACY_LOCAL_WRITE=true, MACHINE_RECEIVER_ENABLED=true, localhost allowlist, and provide LEGACY_DIRECTION_MAP as reviewed JSON mapping the protocol code to one legacy character. Receiver refuses writes with an empty map. Preserve original timestamp/timezone. The new legacy store inserts only tbl_rawdata, FlFlag=0; does not touch tbl_absen/tbl_log or create a second raw table. Production `mkm_ssmdb` writes additionally require the separate physical-receiver gate described in `PHYSICAL_RECEIVER_INVESTIGATION_2026-10-28.md`.

Existing identical tuples are retained on reads. On receiver replay, a matching event tuple (date, time, card, direction, terminal; FlFlag is not identity) is acknowledged as an already-persisted duplicate without inserting another row. This is the only durable identity available in the legacy table and carries a documented risk that two genuine same-second taps with the same tuple are indistinguishable. Cooperative advisory lock protects new-app concurrent inserts only, not legacy external writers. Existing duplicate tuples are not merged. A successful insert has no invented numeric row ID.

## Remaining integration work

Legacy reads/local receiver insertion are implemented. Generated integration mode remains backward compatible and retains shift/overnight/missing-punch/CSV/identity/queue behavior. Persisted legacy processing and dispatch are **not yet bridged** to that engine. Its source FKs reference attendance_raw_events.id; the legacy source has no such key. Do not pretend a tuple hash or PostgreSQL ctid is a stable occurrence ID.

Before enabling real dispatch, agree a durable receipt/occurrence registry in a separate integration store and its ambiguity policy, verify protocol/legacy direction mapping, map user/badge identities, and confirm CSV profile. Then implement processor/queue source adapters and shadow reconciliation. Do not reuse tbl_absen as an IN/OUT result table; it lacks the needed fields. No FlFlag=1 writer is supplied in this phase, per the latest explicit instruction. API failure/unknown outcomes remain guarded by the existing ledger in old integration mode; legacy mode cannot submit at all.

## Validation on 27 October 2026

- Read-only runtime inspection: local MySQL mkm_attendance_local, 17 generated tables; no live production schema inspection.
- `tests/legacy-schema.php`: 18 checks passed on an automatically provisioned random isolated MySQL DB, then removed. Exact fields, seconds, initial FlFlag=0, safe replay hold, same-time/different-terminal retention, identical-row ambiguity, bound parameters, no generated tables, migration/outbound lock, no flag update, and read-only enforcement on all five PDO roles.
- `tests/run.php`: 13 offline checks passed, including CSV precision, HMAC structure, default/submit guards.
- `tests/attendance-engine.php`: 38 pure engine checks passed, including IN/OUT, overnight, missing punches, protected records.
- `tests/database.php`: 13 existing MySQL integration checks passed; fixtures rolled back. Preserves mapping/queue ownership/idempotency and read-only enforcement.
- `tests/legacy-postgres.php`: SKIP when pdo_pgsql unavailable; PostgreSQL connection/insert is **not live validated**. SQL dialect support is implemented but not equivalent to a passed runtime check.
- PHP lint passed for modified/new PHP files. No Talenta call, production DDL, production record update, production task or receiver replacement.

Modified files and purposes: LegacyAttendanceRepository (new exact-schema adapter); Database (read-only sessions for all production legacy roles); bootstrap (mode defaults and loading); IntegrationMigrations/console (legacy migration/write-command refusal and preview command); IntegrationSettings (env-only credential reads); Mekari (legacy dispatch lock); API/index (store routing); home/attendance + legacy-view (read-only source UI); settings/simulator-submit (legacy write refusal). Local fixture SQL, isolated tests, environment templates, audit/deployment docs are separate artifacts. No existing tables/columns or encrypted settings were migrated/deleted.

Additional regression runs: machine 39, settings 19, scheduler 23, legacy-config 10, persisted engine 18 checks passed. Initial new-test failures (missing env override defaults and readonly subprocess argument routing) were corrected, then the full affected tests passed on rerun. Current local UI routes return HTTP 200. No production or remote API operation was used.
