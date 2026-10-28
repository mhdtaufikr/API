<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (($argv[1] ?? '') !== '--reset-confirmed' || Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('Explicit reset is restricted to local mysql mkm_attendance_local.'));
}
$pdo = Database::open();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'mkm_attendance_local') {
    compat_throw(new RuntimeException('Database mismatch.'));
}
$allowed = ['attendance_period_locks', 'attendance_processing_errors', 'attendance_raw_events', 'attendance_requests', 'attendance_shift_assignments', 'attendance_sources', 'attendance_statuses', 'attendance_work_calendar', 'attendances', 'employee_mappings', 'integration_queue', 'integration_settings', 'integration_settings_audit', 'queue_events', 'schema_version', 'shifts', 'terminals'];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (array_diff($tables, $allowed)) {
    compat_throw(new RuntimeException('Unexpected tables present; reset refused.'));
}
$dir = ROOT . '/storage/backups/reset-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($dir, 0700, true)) {
    compat_throw(new RuntimeException('Cannot create backup directory.'));
}
$snapshot = [];
$sql = "-- Local reset backup. Restore only to a separate empty MySQL database.\n";
$pdo->beginTransaction();
try {
    foreach ($tables as $table) {
        $q = Database::quote($table);
        $ddl = $pdo->query('SHOW CREATE TABLE ' . $q)->fetch(PDO::FETCH_NUM)[1];
        $rows = $pdo->query('SELECT * FROM ' . $q)->fetchAll(PDO::FETCH_ASSOC);
        $snapshot[$table] = ['ddl' => $ddl, 'rows' => $rows];
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    compat_throw($e);
}
// Parents first for restore; reverse for deletion. Never disable foreign-key checks.
$dependencies = [];
foreach ($tables as $table) {
    $dependencies[$table] = [];
}
$fks = $pdo->query("SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_NUM);
foreach ($fks as [$child, $parent]) {
    $dependencies[$child][] = $parent;
}
$ordered = [];
while ($dependencies) {
    $progress = false;
    foreach ($dependencies as $table => $parents) {
        if (!array_diff($parents, $ordered)) {
            $ordered[] = $table;
            unset($dependencies[$table]);
            $progress = true;
        }
    }
    if (!$progress) {
        compat_throw(new RuntimeException('Cyclic FK graph; reset refused.'));
    }
}
foreach ($ordered as $table) {
    $sql .= $snapshot[$table]['ddl'] . ";\n";
    foreach ($snapshot[$table]['rows'] as $row) {
        $fields = implode(',', array_map([Database::class, 'quote'], array_keys($row)));
        $values = implode(',', array_map(fn($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), array_values($row)));
        $sql .= 'INSERT INTO ' . Database::quote($table) . ' (' . $fields . ') VALUES (' . $values . ");\n";
    }
}
$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
foreach (['snapshot.json' => $json, 'backup.sql' => $sql, '.env.backup' => file_get_contents(ROOT . '/.env')] as $name => $content) {
    $f = fopen($dir . '/' . $name, 'xb');
    if (!$f) {
        compat_throw(new RuntimeException('Cannot create backup file.'));
    }
    try {
        if (fwrite($f, $content) !== strlen($content) || !fflush($f) || function_exists('fsync') && !fsync($f)) {
            compat_throw(new RuntimeException('Backup durability failed.'));
        }
    } finally {
        fclose($f);
    }
    if (file_get_contents($dir . '/' . $name) !== $content) {
        compat_throw(new RuntimeException('Backup verification failed.'));
    }
}
$manifest = ['database' => 'mkm_attendance_local', 'created_at' => gmdate('c'), 'counts' => array_map(fn($table) => count($table['rows']), $snapshot), 'sql_sha256' => hash_file('sha256', $dir . '/backup.sql'), 'snapshot_sha256' => hash_file('sha256', $dir . '/snapshot.json'), 'restore_order' => $ordered, 'verification' => 'File byte comparison and hashes; restore not executed.'];
file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
// Refuse concurrent row changes after snapshot, before any DDL.
foreach ($tables as $table) {
    if ($pdo->query('SELECT * FROM ' . Database::quote($table))->fetchAll(PDO::FETCH_ASSOC) !== $snapshot[$table]['rows']) {
        compat_throw(new RuntimeException('Data changed after backup. Reset refused.'));
    }
}
foreach (explode(';', preg_replace('/^--.*$/m', '', file_get_contents(ROOT . '/migrations/local_legacy.mysql.sql'))) as $statement) {
    if (trim($statement) !== '') {
        $pdo->exec($statement);
    }
}
$pdo->exec("CREATE TABLE tbl_config (provider VARCHAR(30) PRIMARY KEY,client_id_encrypted TEXT NULL,client_secret_encrypted TEXT NULL,import_token_encrypted TEXT NULL,revision BIGINT NOT NULL DEFAULT 1,audit_history JSON NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB COMMENT='mkm-talenta-config-v1'");
$profile = $snapshot['integration_settings']['rows'][0] ?? null;
if ($profile) {
    $profile['audit_history'] = json_encode($snapshot['integration_settings_audit']['rows'] ?? [], JSON_THROW_ON_ERROR);
    $fields = ['provider', 'client_id_encrypted', 'client_secret_encrypted', 'import_token_encrypted', 'revision', 'audit_history', 'created_at', 'updated_at'];
    $s = $pdo->prepare('INSERT INTO tbl_config (' . implode(',', $fields) . ') VALUES (' . implode(',', array_map(fn($k) => ':' . $k, $fields)) . ')');
    $s->execute(array_intersect_key($profile, array_flip($fields)));
    $stored = $pdo->query('SELECT * FROM tbl_config')->fetch(PDO::FETCH_ASSOC);
    foreach (['client_id_encrypted', 'client_secret_encrypted', 'import_token_encrypted'] as $field) {
        if ($stored[$field] !== $profile[$field]) {
            compat_throw(new RuntimeException('Ciphertext copy verification failed; old tables preserved.'));
        }
    }
}
foreach (array_reverse($ordered) as $table) {
    $pdo->exec('DROP TABLE ' . Database::quote($table));
}
$actual = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($actual);
$expected = ['tbl_absen', 'tbl_config', 'tbl_log', 'tbl_rawdata'];
if ($actual !== $expected) {
    compat_throw(new RuntimeException('Final table verification failed.'));
}
echo 'Backup verified: ' . str_replace(ROOT . '/', '', $dir) . PHP_EOL;
echo 'Exactly four tables: ' . implode(', ', $actual) . PHP_EOL;
echo 'Raw trial events reset to zero; encrypted credentials and credential audit copied. No Talenta request.' . PHP_EOL;
