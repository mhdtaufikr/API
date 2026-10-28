# PostgreSQL production config setup

Use [.env.production.example](.env.production.example) and [POSTGRESQL_PRODUCTION_SETUP.md](docs/POSTGRESQL_PRODUCTION_SETUP.md). New commands config:install / config:seed / config:check handle tbl_config only. PostgreSQL staging validation is required; no production operations were performed locally.

# PHP 7.4 compatibility update

The application now supports PHP 7.4+ and PHP 8.2+. Actual PHP 7.4.33 runtime tests passed. See [PHP74_COMPATIBILITY.md](docs/PHP74_COMPATIBILITY.md) for results, required extensions and server trial limitations. Earlier PHP 8-only statements below are historical. No production cutover or Talenta import was performed during the backport.

# Latest instruction: read-only legacy audit

Panduan terbaru: [LEGACY_COMPATIBILITY.md](docs/LEGACY_COMPATIBILITY.md). .env.example sekarang menggunakan legacy_readonly: tidak menjalankan migrate/seed atau membuat tbl_config di production. Instruksi migration di bawah hanya arsip mode sebelumnya, bukan langkah audit yang aktif.

# Database existing: tbl_config saja

Gunakan .env.example dengan INTEGRATION_STORAGE=legacy_config. Dalam mode ini migrate hanya membuat tbl_config dan seed hanya menginisialisasi konfigurasi. Panduan terbaru: [PASANG_SERVER.md](docs/PASANG_SERVER.md). Adapter attendance legacy belum dihubungkan. Untuk schema integrasi penuh di database terpisah gunakan .env.local.example.

# Panduan penggunaan sederhana

Empat menu: Ringkasan, Data Absensi, Cek di Talenta, dan Pengaturan. Simulator dan alat teknis tersedia dari Data Absensi.

Untuk server existing 172.17.210.228, baca [panduan pemasangan aman](docs/PASANG_SERVER.md). Gunakan folder dan database integrasi baru; jangan jalankan migration pada database attendance legacy.

# MKM Attendance Machine Receiver and Talenta Integration

One PHP Native application: machine GET → local database → shift-aware attendance review → single test employee queue → CSV review and explicit Talenta confirmation. Reception never calls Talenta.

Talenta export is scheduled for **08:00, 12:00, and 18:00 WIB**, with incremental event-level deduplication and support for a valid morning IN before its OUT exists. The scheduler implementation is prepared but remains disabled pending verified configuration and explicit profile approval; no OS task was installed. See [Scheduled Talenta export](docs/TALENTA_SCHEDULER.md).

The root [kirimkeTalenta.bat](kirimkeTalenta.bat) is a compatibility entry point for an existing Windows Task Scheduler action with that filename/path. It selects PHP 8.2+, preserves exit codes and logs output. See [BAT replacement](docs/LEGACY_BAT_REPLACEMENT.md); no existing production BAT/task was replaced or activated locally.

**Tarik Absensi Talenta** is a separate read menu that uses the saved Client ID/Secret to display current remote attendance summaries with date filters and pagination. It was live-verified through Apache XAMPP (HTTP 200). See [Talenta attendance read](docs/TALENTA_ATTENDANCE_READ.md). No new migration or production-write setting is needed for this view.

The **Mekari Credentials** menu now stores service Client ID, Client Secret and Attendance Import Token encrypted in the database. A shared sidebar exposes all menus on desktop and mobile. Run `php bin/console.php migrate` and `php bin/console.php seed` after deployment; see [the deployment guide](docs/DEPLOYMENT.md) for fresh MySQL/PostgreSQL setup and APP_KEY backup requirements. Seed creates an empty profile without overwriting existing credentials or enabling live submissions.

The attendance engine now resolves roster/calendar rules, calculates IN/OUT and metrics, persists idempotent attendance records and protects HR/approved/locked/confirmed values. See [Attendance Engine](docs/ATTENDANCE_ENGINE.md) for native processing commands, policy examples, algorithm, changed files and unresolved HR rules. Run `attendance:process-logs --dry-run` before actual processing; only processed, current and unprotected records can enter the existing explicit export queue. Migration 003 has only been applied locally.

## Current local setup

At the user's request, local development now uses **XAMPP MySQL/MariaDB**. The inspected server is MariaDB 10.4.32, 127.0.0.1:3306. New database **mkm_attendance_local** contains only this integration's tables. The inspected `mkm_operationkpi.attendance_logs` contains daily attendance fields, without the required raw machine/card/timestamp layout, and was not reused or changed. Metadata evidence is private under `storage/`.

PostgreSQL support is retained with its own migration and `.env.pgsql.example`; it has not been verified against a live PostgreSQL instance. No production receiver, physical machine configuration, legacy `mkm_ssmdb`, supplier code, root `index.php`, or scheduled task was changed in this update.

## Run

Windows shortcut: run `start-local.bat`, then open http://127.0.0.1:8088/machine-simulator.php. It reuses an already running compatible server or launches PHP 8.2+ on loopback without changing Apache or other projects. The simulator displays its real runtime version and disables GET ingestion on unsupported Apache runtimes. Receiver failures before persistence are shown as "Not inserted by this request" rather than "Parse only".

Use installed PHP **8.4.16** (meets PHP 8.2+):

```powershell
C:\php\php.exe -S 127.0.0.1:8088 -t public bin/router.php
```

- Dashboard: http://127.0.0.1:8088/
- Simulator: http://127.0.0.1:8088/machine-simulator.php
- Receiver: http://127.0.0.1:8088/API/index.php

XAMPP Apache PHP **8.2.12** supports the dashboard, receiver persistence and queue operations at http://localhost/API/public/. The separate PHP 8.4 server is optional. Apache's global runtime was not changed.

After Apache is upgraded separately, this checkout's receiver path is `/API/public/API/index.php`. The requested `/mkm-talenta-integration/public/API/index.php` is a deployment alias for the same folder, not another application. The simulator derives its receiver URL relative to its page; `MACHINE_SIMULATOR_PATH` can specify another **same-origin** path. No production endpoint is redirected.

Keep the development server bound to loopback. Apache requires `AllowOverride All`. Never serve the repository root with PHP's built-in server: use the public root and supplied router.

## Database configuration and migrations

Credentials remain in `.env`, never HTML or JavaScript. Current local configuration:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mkm_attendance_local
DB_USERNAME=root
DB_PASSWORD=
DRY_RUN=true
ALLOW_PRODUCTION_WRITE=false
MACHINE_RECEIVER_ENABLED=true
MACHINE_ALLOWED_IPS=127.0.0.1,::1
MACHINE_ALLOW_SIMULATOR=true
```

The local root/no-password account was verified against this development server. Configure restricted accounts before external deployment. PHP process environment variables override matching `.env` keys; tests use this without editing credentials.

```powershell
C:\php\php.exe bin/inspect-mysql.php
C:\php\php.exe bin/console.php migrate
C:\php\php.exe bin/console.php inspect
```

MySQL setup only targets local `mkm_attendance_local`. It inspects columns before creation, refuses nonempty unrecognized schemas, and checks the migration checksum on subsequent runs. Existing application tables are never overwritten or dropped. MySQL DDL is not transactional: a failed initial installation requires reviewing any partial new integration tables before retrying; they are not automatically deleted.

Tables: `schema_version`, `attendance_raw_events`, `shifts`, `terminals`, `employee_mappings`, `integration_queue`, `queue_events`, `integration_settings`, `integration_settings_audit`. MySQL event timestamps use `DATETIME(6)` in UTC, converted to the configured shift timezone. Identifiers remain strings. PostgreSQL uses `TIMESTAMPTZ` in the dedicated `mkm_attendance` schema.

For PostgreSQL later, configure `.env` from `.env.pgsql.example`, start its separately installed service, then inspect before creating the owned schema:

```powershell
C:\php\php.exe -d extension=pdo_pgsql bin/console.php inspect
C:\php\php.exe -d extension=pdo_pgsql bin/console.php migrate --separate-schema
```

Conflicting existing schemas are refused. The optional original source-table adapter (`bin/console.php map <file.json>`) remains read-only and validates actual columns. No legacy data transfer is automated.

## Machine request parser

`public/API/index.php` is stateless and accepts the original GET protocol:

```text
API/index.php?cardno=0040002T21202026102509403610&d=2026/10/25&h=09:40&m=004
```

The dedicated parser only supports the supplied **observed-v1** layout:

```text
004 | 0002T | 2120 | 20261025094036 | 10
terminal  opaque  card    timestamp  direction code
```

Assumptions are deliberately narrow: three terminal digits, opaque literal `0002T`, four card digits, fourteen timestamp digits, two direction digits. No meaning is assigned to `0002T` or direction `10`. Other layouts/card lengths need actual protocol evidence before support is added. Alternate terminals must still match the entire layout and GET cross-checks.

The parser validates the calendar timestamp, preserves seconds, cross-checks `d`, `h` (hour/minute) and `m`, and rejects transactions over five minutes in the future. Unknown direction codes are stored as raw events but block attendance review readiness.

## Response contract and simulator

Physical-machine acknowledgement has not been verified. The default **simulation** contract is plain text:

- Committed new event: 200 `OK`.
- Duplicate of an existing committed event: 200 `OK`, same database row.
- Invalid input: 422; unauthorized IP: 403; POST: 405; oversized input: 413.
- Disabled receiver, unsupported PHP, database/audit failure: 503 `ERROR`.

Copy `config/receiver-response.example.json` to `config/receiver-response.json` to configure exact success/duplicate/failure bodies, including any newline. These defaults are not claimed compatible with the physical terminal. Debug metadata is available only through the separate localhost inspector, not JSON machine responses.

The simulator sends actual same-origin HTTP requests with browser fetch. It supports single GET, duplicate GET twice, parse-only inspection, database row/result inspection, and a POST rejection test. POST ingestion remains disabled until its protocol is defined. All writes target the local integration database. The supplied sample remains unmapped until actual employee identities and directions are verified.

Receiver storage uses prepared statements and a unique SHA256 fingerprint. Repeats do not insert another event. A database error is not acknowledged as success. Talenta failures cannot remove or roll back a previously received raw event.

## Explicit employee mappings and shifts

Copy JSON examples to private reviewed files, then configure:

```powershell
C:\php\php.exe bin/console.php shift <reviewed-shift.json>
C:\php\php.exe bin/console.php terminal <reviewed-terminal.json>
C:\php\php.exe bin/console.php employee-map <reviewed-employee-map.json>
```

Mappings explicitly store machine card, employee NIK, Talenta Employee ID, Talenta User ID, fingerprint badge, shift, and `verified`. Missing values remain unverified; no identity is copied into another automatically. Set one authorized `ATTENDANCE_TEST_NIK` and its verified `TALENTA_USER_ID` in `.env`. Other employees remain local-only.

The terminal example deliberately has an empty direction map. Populate raw code → IN/OUT from documented device behavior. Hypothetical direction codes in unit tests do not establish the real meaning of code 10. The shift example is also a template, not a claim about MKM's actual roster.

Shifts define timezone, start/end times, ISO weekdays and separate IN/OUT tolerance windows. Migration 003 adds explicit roster/calendar priorities and grace/rounding/dedup policies. Overnight OUT taps retain the preceding shift date. Duplicate taps are excluded with traceable source IDs; valid earliest IN/latest OUT candidates are selected only within the assigned windows. Unknown directions need explicitly enabled window fallback; ambiguous interpretations require review. Missing punches stay incomplete. See the engine guide for protected HR values and historical roster applicability.

## Integration queue and Talenta

Prepare the test employee queue from the dashboard or CLI:

```powershell
C:\php\php.exe bin/console.php prepare-queue 2026-10-26 2026-10-27
```

Both processor and repository enforce one configured test NIK. Complete verified attendance uniquely claims its raw events in the queue. Mapping, shift, direction or event changes invalidate the preview. Preparing a queue never sends an API request.

The workspace official Postman collection verifies:

```text
POST /v2/talenta/v2/attendance/import-fingerprint
multipart: user_id, token, file (CSV, maximum 5 MB)
scope: talenta:attendance
```

Company scope comes from HMAC credentials; company_id is not an extra multipart field. Verify the credentials belong to company 11423. The documented CSV example is `badgeno,checktime`, which must match Talenta's configured import profile. The adapter uses explicit **badge_number**, not automatically NIK/card. The canonical sample CSV is only a review artifact.

The example profile has minute precision. Nonzero seconds, including the supplied `09:40:36` sample, cause a validation error rather than silent truncation. Configure the actual approved seconds-capable profile after verification with Talenta.

```powershell
C:\php\php.exe bin/console.php postman 'Talenta Data API V2 - MAG HMAC Backend Integration.postman_collection.json'
C:\php\php.exe bin/console.php verify-contract <reviewed-contract.json>
```

The collection hash and exact multipart keys are checked. Example credentials from the collection are never used. HMAC follows [Mekari's official PHP documentation](https://developers.mekari.com/docs/kb/hmac-authentication/php): RFC7231 GMT date plus POST request line, HMAC-SHA256 and Base64 signature.

CSV review/download works without API credentials. Live Trial requires all of:

- Company-owned Client ID, Client Secret and Import Token entered through the Mekari Credentials menu; APP_KEY remains in `.env`.
- Verified company, test NIK, employee/card/user/badge mapping, device directions and shift.
- Reviewed import profile and `TALENTA_CSV_PROFILE_CONFIRMED=true`.
- `DRY_RUN=false` and `ALLOW_PRODUCTION_WRITE=true`.
- Fresh queue preview, checkbox, and typing `SEND <configured NIK>` for every request.

Confirmation expires after ten minutes and is invalidated by configuration/source changes. Queue reservation and the independent file ledger block concurrent/overlapping attempts before network I/O. TLS validation stays enabled; redirects and automatic retries are disabled. Failures/timeouts do not release reservations or delete raw events.

HTTP 2xx means **submitted pending reconciliation**, not confirmed import. After checking Talenta, record manual evidence with:

```powershell
C:\php\php.exe bin/console.php reconcile-queue <id> success <non-secret-Talenta-reference>
```

Use `failed` for a verified failure. Reconciliation records evidence without clearing duplicate protection. Back up the database and `storage/ledger.json`. No actual Talenta submission has been performed.

## Security

- IP allowlist uses only REMOTE_ADDR. Defaults permit loopback; observed physical machine IPs are not enabled automatically.
- Cross-site browser injection is rejected. Dashboard, simulator, inspector and queue enforce localhost access. Approval actions use CSRF protection.
- Exactly four query parameters, no repeated keys, 1024-byte query limit, no GET body, 12-second receiver execution limit, connection/query/lock timeouts.
- Apache/router blocks .env, internal configuration, migrations, logs and Postman evidence from browser access.
- Audit logs preserve bounded protocol fields and safe error codes, without returning credential/database error text. API logs store status, digest and outcome instead of arbitrary remote bodies.
- Optional restricted role groups are provided in `migrations/permissions.mysql.example.sql` and PostgreSQL `permissions.example.sql`. Configure DB_READER_*, DB_RECEIVER_*, DB_WORKER_* with distinct company-owned users. The reader connection is read-only. Migration/configuration uses the base administrator connection. The local root fallback must not be externally deployed.
- Physical deployment still requires verified acknowledgement, real network controls and restricted accounts. No machines or production endpoints are reconfigured by this app.

## Tests

```powershell
C:\php\php.exe tests/run.php
C:\php\php.exe tests/machine.php
C:\php\php.exe tests/database.php
C:\php\php.exe tests/settings.php
C:\php\php.exe tests/settings-http.php
powershell -NoProfile -File tests/http.ps1
```

Locally validated: 13 existing checks, 39 receiver/processor checks, and 13 real MySQL integration checks. Database tests cover prepared inserts, raw JSON round-trip, UTC preservation, duplicate protection, mapping/shift processing, queue idempotency and overlapping claims, mapped CSV, reservations, reconciliation and read-only permissions. Fixtures roll back; no Talenta call occurs. The in-memory unit-test adapter exists only in tests, never as an application fallback.

Eleven HTTP checks also passed for real GET insert/duplicate round-trips, an alternate terminal, a second event, malformed/mismatched/oversized input, repeated parameters, POST rejection and the dashboard. The HTTP test leaves the three supplied/simulated raw events in the local database. Browser verification confirmed persistence and duplicate responses; a controlled database failure was verified before configuration. PostgreSQL-specific integration tests remain unverified until that service is available.

Credential configuration adds 19 encryption/database checks and 24 HTTP settings/sidebar checks, for 119 passed checks overall. These cover field-bound authenticated encryption, wrong-key/tamper rejection, metadata-only auditing, stale-form rejection, seed preservation, blank-field retention, explicit clear, HTML secrecy, CSRF, and runtime database credential resolution. Fixture credentials were cleared or rolled back; no Talenta request was made.
