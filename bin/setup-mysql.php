<?php

declare (strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
try {
    if (PHP_VERSION_ID < 70400) {
        compat_throw(new RuntimeException('Use PHP 7.4+.'));
    }
    if (Database::driver() !== 'mysql' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || env('DB_DATABASE') !== 'mkm_attendance_local') {
        compat_throw(new RuntimeException('Setup is restricted to local mysql database mkm_attendance_local.'));
    }
    $pdo = new PDO('mysql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT') . ';charset=utf8mb4', env('DB_USERNAME'), env('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
    $stmt = $pdo->prepare('SELECT GET_LOCK(:name,5)');
    $stmt->execute(['name' => 'mkm-attendance-local-setup']);
    if ((int) $stmt->fetchColumn() !== 1) {
        compat_throw(new RuntimeException('Another setup is running.'));
    }
    $stmt = $pdo->prepare('SELECT table_schema AS table_schema,table_name AS table_name,column_name AS column_name,data_type AS data_type FROM information_schema.columns WHERE table_schema=:schema ORDER BY table_name,ordinal_position');
    $stmt->execute(['schema' => env('DB_DATABASE')]);
    $before = $stmt->fetchAll(PDO::FETCH_ASSOC);
    saveJson(ROOT . '/storage/mysql-integration-before-migration.json', $before);
    if ($before) {
        (new AttendanceRepository())->assertInstalled();
        echo "Local integration database already installed; no changes made.\n";
        exit;
    }
    // Existing empty database is safe to initialize; any nonempty unrecognized schema above fails closed.
    $pdo->exec('CREATE DATABASE IF NOT EXISTS mkm_attendance_local CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $pdo->exec('USE mkm_attendance_local');
    $sql = file_get_contents(Database::migrationFile());
    foreach (explode(';', preg_replace('/^--.*$/m', '', $sql)) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    $stmt = $pdo->prepare('INSERT INTO schema_version (version,checksum) VALUES (:version,:checksum)');
    $stmt->execute(['version' => 1, 'checksum' => hash('sha256', $sql)]);
    echo "Installed mkm_attendance_local only. Existing application databases were not modified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Setup failed: ' . ($error instanceof PDOException ? 'Database setup error. Review local database and any partial new integration tables before retrying.' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
