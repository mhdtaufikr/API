<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('Audit restricted to local MySQL mkm_attendance_local.'));
}
$pdo = Database::connect();
try {
    $actual = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== 'mkm_attendance_local') {
        compat_throw(new RuntimeException('Active database mismatch.'));
    }
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . Database::quote($table))->fetchColumn();
    }
    echo json_encode(['driver' => Database::driver(), 'database' => $actual, 'mode' => env('INTEGRATION_STORAGE', 'integration'), 'read_only' => true, 'table_counts' => $counts, 'required_missing' => array_values(array_diff(['tbl_rawdata', 'tbl_absen', 'tbl_log', 'tbl_config'], $tables))], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
