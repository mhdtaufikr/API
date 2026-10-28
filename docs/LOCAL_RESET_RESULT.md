# Local reset result — 27 October 2026

User explicitly authorized discarding/restarting local trial data after the initial migration blocker. Therefore this operation was a reset, not a semantic migration of protocol direction 10/20 into one-character legacy direction.

Verified target: mysql, loopback host, SELECT DATABASE() = mkm_attendance_local. No mkm_ssmdb/production connection. Executed fixed target guard and allowlist script `bin/reset-local-four-tables.php --reset-confirmed`.

Before DDL, created private backup:
`storage/backups/reset-20261027-135511-65a045/`

Contains full 17-table SQL dump in dependency order, snapshot JSON, counts/SHA-256 manifest, and original .env including APP_KEY. Backup files were flushed/fsynced and read back byte-for-byte. Snapshot was compared against live rows before DDL. Restore was **not** executed; this is not a claim of restore-tested recovery. storage/.htaccess denies HTTP access; protect the same directory in any other web server configuration. Do not commit or expose these files.

Created and checked tbl_rawdata/tbl_absen/tbl_log/tbl_config, copied existing encrypted credential ciphertext and credential-change audit history to tbl_config, verified ciphertext equality, then dropped 17 generated tables in reverse foreign-key dependency order. No DROP DATABASE or foreign-key-check disable used. Existing APP_KEY was retained. Old raw payloads and status masters remain in backup; not copied to raw legacy records without direction mapping.

Final schema and counts:

| Table | Rows |
|---|---:|
| tbl_rawdata | 0 |
| tbl_absen | 0 |
| tbl_log | 0 |
| tbl_config | 1 |

Active .env: INTEGRATION_STORAGE=legacy_app, APP_ENV=local. Live write, scheduler, receiver, and simulator are disabled. No Talenta request. Home/data routes use legacy raw adapter, credentials use tbl_config, old technical/queue routes redirect into legacy preview, archived AttendanceRepository refuses use in this mode. Old integration code remains in repository for reference/regression and is not compatible with this reset DB; do not switch back to integration or run old migrations/tests that require generated DB tables.

Checks after reset:
- Four-table local test: 9 checks passed; timestamp and settings fixture updates rolled back.
- Offline API/CSV/HMAC checks: 13 passed; no request.
- Pure attendance engine: 38 passed. This does not mean persisted processing is now connected to the four-table schema.
- Home/data/settings/technical/queue routes returned HTTP 200.
- Final read-only table inventory confirms exactly four approved tables and no fixture rows.

Remaining work: four-table manual/scheduled dispatch and persisted shift-engine replacement are not activated. PostgreSQL provisioning files now exist under database/ but PostgreSQL runtime tests have not passed locally (not installed). Current tbl_config credential storage adapter is still MySQL-specific; future production PostgreSQL credential adapter must be completed/tested before deployment. Receiver direction mapping remains unverified; reset did not invent a mapping.

Reset script is destructive and one-shot: rerun refuses required/new/unexpected tables, so it must not be used as an ordinary migration. Use database schema files only for deliberate empty-database provisioning; nothing runs on page load.
