# Latest instruction: read-only legacy audit

Panduan terbaru: [LEGACY_COMPATIBILITY.md](LEGACY_COMPATIBILITY.md). .env.example sekarang menggunakan legacy_readonly: tidak menjalankan migrate/seed atau membuat tbl_config di production. Instruksi migration di bawah hanya arsip mode sebelumnya, bukan langkah audit yang aktif.

# Pilih mode terlebih dahulu

Untuk existing mkm_ssmdb, ikuti [PASANG_SERVER.md](PASANG_SERVER.md) dan .env.example dengan INTEGRATION_STORAGE=legacy_config. migrate/seed pada mode itu hanya memakai tbl_config. Bagian panduan di bawah khusus mode integration dengan database integrasi terpisah dan .env.local.example; jangan diterapkan sebagai migration penuh pada database legacy.

Mulai dari panduan Indonesia: [PASANG_SERVER.md](PASANG_SERVER.md). Untuk server existing, gunakan database integrasi terpisah; jangan arahkan migration ke database legacy.

# Deployment: migrations, seed, and credentials

This is PHP Native, so use `bin/console.php`, not Laravel Artisan. Current local testing uses XAMPP MariaDB/MySQL. MySQL migration 001 and 002 have been run locally; PostgreSQL SQL is provided but has not been executed against a PostgreSQL instance.

## Existing integration deployment

Deploy the application code, including both migration versions. Keep the server's existing `.env`, `APP_KEY`, database, and persistent `storage` directory. Configure the production integration database and the administrator connection in `.env`; do not point at the legacy attendance database.

Run under PHP 8.2+:

```sh
php bin/console.php migrate
php bin/console.php seed
```

The migration checks ownership/checksums and only applies missing versions. Version 002 adds encrypted credential configuration; version 003 extends the existing shift master and adds attendance processing structures. Raw events, mappings, credentials and queue data are preserved. Seed initializes an absent Talenta profile and missing attendance status masters without overwriting existing definitions. Repeated runs are supported. Configure actual policies/rosters/calendar before processing; see ATTENDANCE_ENGINE.md.

When no APP_KEY exists and there are no encrypted credentials, seed generates a new encryption key directly into `.env` without printing it. The service user must be able to read this file; the deployment user must be able to update it for initial key generation. Alternatively provision a company-owned `APP_KEY=base64:<32 random bytes encoded as base64>` through secure server configuration before seeding. `php bin/console.php key:generate` is also available after migration and never rotates an existing key.

**Keep the original APP_KEY with database backups.** It is intentionally not stored in the database. If encrypted credentials already exist but the key is missing, seed refuses to generate a replacement. Restore the original key. Do not regenerate keys on subsequent deployments.

Open `settings.php` through the existing localhost-only operator access (or a localhost tunnel). Enter the service Client ID, Client Secret and Mekari-issued Attendance Import Token. Existing values never appear in HTML; blank inputs retain saved values. Explicit clear checkboxes remove selected fields. Saving changes increments the revision and invalidates pending live-review sessions. No API request is made.

The database profile is authoritative once seeded. The original `.env` MEKARI_CLIENT_ID/SECRET/IMPORT_TOKEN values are only a compatibility fallback before a database profile exists; they are not imported or overwritten by the seed. Fill the database profile through the menu. Company ID, API host, test NIK/user, import-profile confirmation, DRY_RUN and ALLOW_PRODUCTION_WRITE remain environment configuration.

## Fresh MySQL/MariaDB server

Create a dedicated empty integration database and a company-owned database user using normal DBA provisioning. Use letters, digits and underscores in the database name. Configure `DB_CONNECTION=mysql`, host, port, database and credentials in `.env` using `.env.example` as the variable list. Then run the same migrate and seed commands above.

The migration runner supports a pre-created empty database at the configured production host. It refuses existing unrecognized tables. It never creates a remote database or changes the legacy database. Only the known local XAMPP database `mkm_attendance_local` may be created automatically by the development setup helper.

MySQL DDL is not transactional. On an interrupted first migration, inspect any partial newly created tables before retrying; the application fails closed rather than assuming an existing table is compatible or dropping it.

## Fresh PostgreSQL server

Configure `.env` from `.env.pgsql.example`, enable PDO PostgreSQL, provision the dedicated database and database user, and inspect the database first:

```sh
php bin/console.php inspect
php bin/console.php migrate --separate-schema
php bin/console.php seed
```

The initial migration creates the owned `mkm_attendance` schema. Subsequent deployment updates use ordinary `migrate` and `seed`. PostgreSQL migrations run within a transaction. Do not treat the provided PostgreSQL adapter as live-verified until these operations and integration tests have passed on that server.

## Database permissions

Migration commands use the base administrator connection. Runtime connections can use distinct roles:

- `DB_READER_USERNAME/PASSWORD`: read operations and encrypted credential resolution.
- `DB_RECEIVER_USERNAME/PASSWORD`: raw-event insert/select only; no credential access needed.
- `DB_WORKER_USERNAME/PASSWORD`: queue processing and encrypted credential resolution.
- `DB_SETTINGS_USERNAME/PASSWORD`: credential-profile select/insert/update and metadata-only audit inserts.

Blank role usernames fall back to DB_USERNAME/DB_PASSWORD for local development. Configure restricted company-owned users for production. Optional role SQL is in `migrations/permissions.mysql.example.sql` and `migrations/permissions.example.sql`. Review these templates before applying them; they do not create login passwords or run automatically. Existing PostgreSQL reader/worker roles need SELECT on the new credential table after migration 002. The settings role also needs audit sequence usage in PostgreSQL.

Keep the dashboard and configuration pages localhost-only. This update does not add remote authentication or change the receiver's IP allowlist. Web and CLI runtimes must be PHP 8.2+; XAMPP Apache PHP 8.2.12 supports the receiver when the configured PDO driver is installed.

Keep DRY_RUN=true, ALLOW_PRODUCTION_WRITE=false, API credentials empty until available, and all existing trial/profile/employee guards in place. The seed never enables live writes, imports example tokens, or submits attendance. Obtain the Import Token from Mekari; it cannot be generated by this application.

## Verification

```sh
php tests/settings.php
php tests/settings-http.php http://127.0.0.1:8088
```

The first suite rolls back credential fixtures. The HTTP suite only runs its mutation checks when the profile is empty, writes clearly identified test values, then clears them; it skips writes when real credentials are already configured. Neither suite calls Talenta. Do not run test suites that insert simulated attendance on a production database.
