# Verified existing production database — 27 October 2026

User ran the MariaDB client directly on the server and confirmed:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mkm_ssmdb
DB_USERNAME=it
DB_PASSWORD=
```

Fill password directly in the new application's .env on the server. Do not send it through chat or commit it. Server version is 10.4.30-MariaDB. Existing tables: tbl_absen, tbl_log, tbl_rawdata. tbl_config is absent. Client location: C:\Program Files\MariaDB 10.4\bin\mysql.exe. Server CLI PHP is 7.4.30; pdo_mysql is present. The new app has been tested on PHP 7.4.33 locally, not on that server's exact runtime.

`.env.production.example` now reflects this verified connection. PostgreSQL remains an optional future target, not the existing server database. The PostgreSQL guide is not the deployment procedure for this MariaDB server.

Before any CREATE/seed/cutover: deploy the new code in a separate folder, backup existing database/app/task, inspect exact legacy DDL, and check the it account grants. A successful SELECT does not prove CREATE/INSERT/UPDATE privileges.

Read-only permission/schema check on the server:

```powershell
& 'C:\Program Files\MariaDB 10.4\bin\mysql.exe' `
    -h 127.0.0.1 -P 3306 -u it -p `
    -e 'SHOW GRANTS FOR CURRENT_USER(); SHOW CREATE TABLE mkm_ssmdb.tbl_rawdata; SHOW CREATE TABLE mkm_ssmdb.tbl_absen; SHOW CREATE TABLE mkm_ssmdb.tbl_log;'
```

After backup and privilege review, the new application's config:install/config:seed/config:check commands can set up tbl_config only in legacy_app mode. They cannot run from the old encoded legacy application folder, which has no bin/console.php. Existing raw-writer production guard and scheduler limitations still need separate cutover work; confirming credentials does not make machine ingestion or scheduled sending production-ready. Keep receiver/live/scheduler off until validated. Do not replace existing BAT or delete the old application yet.
