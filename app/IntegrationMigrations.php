<?php

declare (strict_types=1);
final class IntegrationMigrations
{
    public static function table(string $name): string
    {
        if ($name === 'integration_settings' && LegacyConfigMigration::active()) {
            return LegacyConfigMigration::table();
        }
        if (!preg_match('/\A[a-z_]+\z/', $name)) {
            compat_throw(new InvalidArgumentException('Invalid integration table.'));
        }
        return Database::driver() === 'mysql' ? Database::quote(env('DB_DATABASE')) . '.' . Database::quote($name) : 'mkm_attendance.' . Database::quote($name);
    }
    public static function run(bool $separateSchema = false): array
    {
        if (LegacyAttendanceRepository::active()) {
            compat_throw(new RuntimeException('Legacy source modes do not run migrations. Provision local copies explicitly; production schema stays unchanged.'));
        }
        if (LegacyConfigMigration::active()) {
            return LegacyConfigMigration::run();
        }
        if (PHP_VERSION_ID < 70400) {
            compat_throw(new RuntimeException('Migrations require PHP 7.4+.'));
        }
        $pdo = Database::open();
        $mysql = Database::driver() === 'mysql';
        $messages = [];
        $locked = false;
        try {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT GET_LOCK(:name,5)');
                $stmt->execute(['name' => 'mkm-attendance-migrations-' . substr(hash('sha256', env('DB_DATABASE')), 0, 16)]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    compat_throw(new RuntimeException('Another migration is running.'));
                }
                $locked = true;
            } else {
                $pdo->beginTransaction();
                $pdo->exec('SELECT pg_advisory_xact_lock(18421027)');
            }
            $columns = Database::columns($pdo);
            saveJson(ROOT . '/storage/schema-before-migration.json', $columns);
            $schema = $mysql ? env('DB_DATABASE') : 'mkm_attendance';
            $owned = array_values(array_filter($columns, fn($column) => $column['table_schema'] === $schema));
            $tables = array_unique(array_column($owned, 'table_name'));
            if (!in_array('schema_version', $tables, true)) {
                if ($owned) {
                    compat_throw(new RuntimeException('Configured integration schema contains unrecognized tables. Nothing overwritten.'));
                }
                if (!$mysql && !$separateSchema) {
                    compat_throw(new RuntimeException('Inspect the database, then use migrate --separate-schema to create the owned PostgreSQL schema.'));
                }
                self::executeSql($pdo, file_get_contents(Database::migrationFile()));
                self::record($pdo, 1, hash_file('sha256', Database::migrationFile()));
                $messages[] = 'Migration 001 installed.';
            }
            (new AttendanceRepository($pdo))->assertInstalled();
            $versions = $pdo->query('SELECT version,checksum FROM ' . self::table('schema_version'))->fetchAll(PDO::FETCH_KEY_PAIR);
            if (array_filter(array_keys($versions), fn($version) => (int) $version > 3)) {
                compat_throw(new RuntimeException('Database schema is newer than this application.'));
            }
            foreach ([2 => ['002_integration_credentials', ['integration_settings', 'integration_settings_audit']], 3 => ['003_attendance_engine', ['attendance_shift_assignments', 'attendance_work_calendar', 'attendance_statuses', 'attendance_requests', 'attendance_period_locks', 'attendances', 'attendance_sources', 'attendance_processing_errors']]] as $version => [$name, $newTables]) {
                $file = ROOT . '/migrations/' . $name . '.' . ($mysql ? 'mysql.sql' : 'sql');
                $checksum = hash_file('sha256', $file);
                if (isset($versions[$version])) {
                    if (!hash_equals($versions[$version], $checksum)) {
                        compat_throw(new RuntimeException('Migration ' . $version . ' checksum differs.'));
                    }
                    $messages[] = 'Migration ' . $version . ' already installed.';
                    continue;
                }
                if (array_intersect($newTables, $tables)) {
                    compat_throw(new RuntimeException('Migration ' . $version . ' tables already exist without migration ownership. Review before retrying.'));
                }
                if ($version === 3) {
                    foreach ($owned as $column) {
                        if ($column['table_name'] === 'shifts' && in_array($column['column_name'], ['late_grace_minutes', 'early_leave_grace_minutes', 'duplicate_tap_seconds', 'window_fallback_enabled', 'minute_rounding', 'grace_mode'], true)) {
                            compat_throw(new RuntimeException('Unowned/partial shift-policy columns require review before migration 003.'));
                        }
                    }
                }
                self::executeSql($pdo, file_get_contents($file));
                self::record($pdo, $version, $checksum);
                $messages[] = 'Migration ' . $version . ' installed.';
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            return $messages;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            compat_throw($error);
        } finally {
            if ($locked) {
                $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $stmt->execute(['name' => 'mkm-attendance-migrations-' . substr(hash('sha256', env('DB_DATABASE')), 0, 16)]);
            }
        }
    }
    private static function executeSql(PDO $pdo, string $sql): void
    {
        foreach (explode(';', preg_replace('/^--.*$/m', '', $sql)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
    private static function record(PDO $pdo, int $version, string $checksum): void
    {
        $stmt = $pdo->prepare('INSERT INTO ' . self::table('schema_version') . ' (version,checksum) VALUES (:version,:checksum)');
        $stmt->execute(['version' => $version, 'checksum' => $checksum]);
    }
}
