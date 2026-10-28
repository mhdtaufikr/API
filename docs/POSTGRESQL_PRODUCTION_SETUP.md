# PostgreSQL production: add tbl_config only

**Update 27 October 2026:** the existing server has now been verified as **MariaDB 10.4.30**, database **mkm_ssmdb**, user **it**. Use `.env.production.example` and [VERIFIED_PRODUCTION_DATABASE.md](VERIFIED_PRODUCTION_DATABASE.md) for that server. This document is only for a separately planned future PostgreSQL deployment.

Prepared 27 October 2026. No production/staging database connection was used during implementation. New commands validated locally against MySQL (idempotent install/seed/check and preserved credentials). PostgreSQL adapter/DDL/audit use PostgreSQL syntax and pass PHP 7.4 lint, but require real PostgreSQL staging execution before production deployment.

## 1. Backup and PHP

Backup current application, PostgreSQL database, existing .env/APP_KEY if present, receiver routing and Windows tasks. Keep existing production receiver until cutover verification. On the intended CLI and Apache runtimes enable pdo_pgsql, curl, openssl, mbstring. Future intended production PHP is 8.3; temporary PHP 7.4 is supported by application code. Verify both actual runtimes independently.

```bat
C:\xampp\php\php.exe -v
C:\xampp\php\php.exe -m
```

## 2. New application folder and .env

Do not overwrite another application's .env. Copy `.env.production.example` to `.env` in the new application folder and fill PostgreSQL host/database/user/password directly on the server. Use a migration account authorized to CREATE in the selected schema for config:install. The existing database/schema must already exist; this command will not create a database/schema.

```ini
APP_ENV=production
INTEGRATION_STORAGE=legacy_app
DB_CONNECTION=pgsql
DB_HOST=<actual PostgreSQL host>
DB_PORT=5432
DB_DATABASE=<actual production database>
DB_USERNAME=<authorized account>
DB_PASSWORD=<fill privately>
LEGACY_SCHEMA=public
APP_KEY=
DRY_RUN=true
ALLOW_PRODUCTION_WRITE=false
TALENTA_SCHEDULER_ENABLED=false
MACHINE_RECEIVER_ENABLED=false
```

If legacy tables are in a schema other than public, set LEGACY_SCHEMA to the actual schema. Preserve exact mixed-case legacy columns; do not rename/lowercase them to work around a mismatch. Inspect first:

```bat
cd /d C:\xampp\htdocs\<new-app-folder>
C:\xampp\php\php.exe bin\console.php inspect
```

inspect reads metadata and writes a private local metadata file; it does not change database structure. Keep its HR/schema output private.

## 3. Install and seed configuration only

```bat
C:\xampp\php\php.exe bin\console.php config:install
C:\xampp\php\php.exe bin\console.php config:seed
C:\xampp\php\php.exe bin\console.php config:check
```

- config:install creates **only tbl_config**, with JSONB audit_history, PK provider, and ownership comment. Legacy tables are not altered. Advisory lock/transaction protect PostgreSQL installation. An existing unowned/incompatible tbl_config causes refusal, not overwrite/drop. Repeated install is a no-op for a matching owned table.
- config:seed inserts the empty provider=talenta profile only if absent. It generates APP_KEY into .env only when needed and safe. Existing encrypted profile/key are not overwritten; missing key with existing ciphertext causes refusal.
- config:check reads connectivity/decryption/configured booleans without outputting credential values.

Do **not** use generic migrate/seed or local cleanup/reset scripts for this deployment. Do **not** execute database/postgresql_schema.sql on a database already containing legacy tables: that file is only for deliberate clean provisioning of all four tables. These new commands are specifically for adding config alone.

After seeding, backup the new .env with APP_KEY together with the DB. When moving ciphertext from a prior integration instead of starting with blank config, preserve the original APP_KEY; never generate a replacement key for that ciphertext. Backup/restore must keep database, key, and dispatch ledger together.

## 4. Credentials and permissions

Open dashboard/settings from browser **on the server** (localhost-only), for example http://localhost/<new-app-folder>/public/settings.php. Fill Client ID, Client Secret, Import Token from **Attendance MKM**, then run config:check again. Database seeded settings take precedence; MEKARI_* values in .env are not automatically imported into the seeded empty profile.

Config is encrypted in tbl_config; audit metadata also stays in that table. No separate settings/audit table is created. Limit runtime roles to necessary privileges: reader SELECT on actual legacy source and tbl_config; settings SELECT/INSERT/UPDATE on tbl_config. Migration CREATE permission is needed only for install. Configure separate DB_* role credentials where appropriate. Do not expose configuration pages to the LAN by removing localhost protection.

Retain the verified contract and identity map privately: config/contract.json must match Attendance MKM (date 1 Y-m-d, time 2 H:i:s, barcode 3, starting row 2, Range); config/legacy-trial-map.json currently scopes manual trial to card 2120/company 11423. Review whether that company/identity is still correct before deployment. These runtime files are ignored by Git and need intentional deployment; copy them from the verified source, not an old backup. Do not copy local trial ledger/backups wholesale. Preserve the active ledger when migrating a continuing integration so previous remote attempts are not forgotten.

## 5. Validate on PostgreSQL staging

Set APP_ENV=staging in a separate staging deployment and use staging DB credentials. Execute the three config commands twice, confirm credentials persist, save/retain/clear test credentials through settings, then:

```bat
php tests/pgsql-config-staging.php
php tests/legacy-postgres.php
```

legacy-postgres test currently requires its isolated local legacy_local configuration; do not redirect it to production. Do not run MySQL fixture suites or destructive test helpers on production. PostgreSQL connection, seed/save audit JSONB, mixed-case raw retrieval, source write/flag transaction and company-scoped API reads need actual staging verification before rollout.

## 6. Before enabling machines or dispatch

Config installation alone is **not production receiver/scheduler cutover**. The current raw writer intentionally retains its isolated-local write guard and rejects mkm_ssmdb; production source writes require separately reviewed enablement. Protocol-to-legacy direction mapping must be verified. Configure real machine IP allowlist, acknowledgement body, /API/ path, and correct original timestamps. Four-table scheduled dispatch is not made complete by these config commands; do not replace the legacy BAT/task yet. Leave DRY_RUN=true, production writes/receiver/scheduler off until staging acceptance and explicit cutover.

Rollback: stop the new task/receiver and restore the previous app/routing/config as needed. Do not drop legacy tables or delete tbl_config to undo application deployment. Recover APP_KEY with the corresponding ciphertext backup and reconcile remote submissions before resending.
