<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (($argv[1] ?? '') !== '--confirmed-reset' || Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || env('INTEGRATION_STORAGE') !== 'legacy_app') {
    compat_throw(new RuntimeException('Explicit local four-table trial reset only.'));
}
if (enabled('TALENTA_SCHEDULER_ENABLED')) {
    compat_throw(new RuntimeException('Disable scheduler before trial reset.'));
}
$pdo = Database::open();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'mkm_attendance_local') {
    compat_throw(new RuntimeException('Wrong target.'));
}
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
if ($tables !== ['tbl_absen', 'tbl_config', 'tbl_log', 'tbl_rawdata']) {
    compat_throw(new RuntimeException('Unexpected schema.'));
}
$dispatch = fopen(ROOT . '/storage/legacy-manual-send.lock', 'c+');
$ledger = fopen(ROOT . '/storage/ledger.lock', 'c+');
if (!$dispatch || !flock($dispatch, LOCK_EX | LOCK_NB)) {
    compat_throw(new RuntimeException('Sender active; reset refused.'));
}
if (!$ledger || !flock($ledger, LOCK_EX | LOCK_NB)) {
    compat_throw(new RuntimeException('Ledger busy; reset refused.'));
}
$key = 'mkm-legacy-receiver-' . substr(hash('sha256', env('DB_DATABASE')), 0, 24);
$lock = $pdo->prepare('SELECT GET_LOCK(:name,5)');
$lock->execute(['name' => $key]);
if ((int) $lock->fetchColumn() !== 1) {
    compat_throw(new RuntimeException('Receiver active; reset refused.'));
}
$directory = ROOT . '/storage/backups/trial-clear-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
try {
    if (!mkdir($directory, 0700, true)) {
        compat_throw(new RuntimeException('Backup directory unavailable.'));
    }
    $snapshot = [];
    foreach ($tables as $table) {
        $snapshot[$table] = ['ddl' => $pdo->query('SHOW CREATE TABLE ' . Database::quote($table))->fetch(PDO::FETCH_NUM)[1], 'rows' => $pdo->query('SELECT * FROM ' . Database::quote($table))->fetchAll(PDO::FETCH_ASSOC)];
    }
    $files = ['.env' => ROOT . '/.env', 'contract.json' => ROOT . '/config/contract.json', 'legacy-trial-map.json' => ROOT . '/config/legacy-trial-map.json'];
    foreach (['ledger.json', 'receiver.jsonl', 'legacy-dispatch.jsonl', 'scheduler-state.json', 'scheduler-approval.json', 'scheduler-console.log', 'trial-generation.json'] as $name) {
        if (is_file(ROOT . '/storage/' . $name)) {
            $files[$name] = ROOT . '/storage/' . $name;
        }
    }
    $payloads = ['database-snapshot.json' => json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)];
    $sql = "-- Archived local trial data. Restore privately into a separate empty MySQL schema.\n";
    foreach ($snapshot as $table => $data) {
        $sql .= $data['ddl'] . ";\n";
        foreach ($data['rows'] as $row) {
            $sql .= 'INSERT INTO ' . Database::quote($table) . ' (' . implode(',', array_map([Database::class, 'quote'], array_keys($row))) . ') VALUES (' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row))) . ");\n";
        }
    }
    $payloads['backup.sql'] = $sql;
    foreach ($files as $name => $path) {
        $value = file_get_contents($path);
        if ($value === false) {
            compat_throw(new RuntimeException('Cannot read backup source.'));
        }
        $payloads[$name] = $value;
    }
    $hashes = [];
    foreach ($payloads as $name => $content) {
        $f = fopen($directory . '/' . $name, 'xb');
        if (!$f) {
            compat_throw(new RuntimeException('Cannot create backup.'));
        }
        try {
            if (fwrite($f, $content) !== strlen($content) || !fflush($f) || function_exists('fsync') && !fsync($f)) {
                compat_throw(new RuntimeException('Backup durability failed.'));
            }
        } finally {
            fclose($f);
        }
        if (file_get_contents($directory . '/' . $name) !== $content) {
            compat_throw(new RuntimeException('Backup verification failed.'));
        }
        $hashes[$name] = hash('sha256', $content);
    }
    saveJson($directory . '/manifest.json', ['at' => gmdate('c'), 'database' => 'mkm_attendance_local', 'counts' => array_map(fn($d) => count($d['rows']), $snapshot), 'sha256' => $hashes, 'warning' => 'User-authorized LOCAL trial reset. Remote Talenta attendance is not deleted. Restore not executed.']);
    $pdo->beginTransaction();
    try {
        foreach (['tbl_rawdata', 'tbl_absen', 'tbl_log'] as $table) {
            $pdo->exec('DELETE FROM ' . Database::quote($table));
        }
        if ($pdo->query('SELECT * FROM tbl_config')->fetchAll(PDO::FETCH_ASSOC) !== $snapshot['tbl_config']['rows']) {
            compat_throw(new RuntimeException('Configuration changed during reset.'));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        compat_throw($e);
    }
    saveJson(ROOT . '/storage/ledger.json', []);
    saveJson(ROOT . '/storage/trial-generation.json', ['generation' => bin2hex(random_bytes(24)), 'reset_at' => gmdate('c')]);
    foreach (['receiver.jsonl', 'legacy-dispatch.jsonl', 'scheduler-console.log'] as $name) {
        if (file_put_contents(ROOT . '/storage/' . $name, '', LOCK_EX) !== 0) {
            compat_throw(new RuntimeException('Cannot clear trial log: ' . $name));
        }
    }
    if (is_file(ROOT . '/storage/scheduler-state.json')) {
        saveJson(ROOT . '/storage/scheduler-state.json', []);
    }
    if (is_file(ROOT . '/storage/scheduler-approval.json')) {
        saveJson(ROOT . '/storage/scheduler-approval.json', []);
    }
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . Database::quote($table))->fetchColumn();
    }
    if ($counts['tbl_rawdata'] || $counts['tbl_absen'] || $counts['tbl_log']) {
        compat_throw(new RuntimeException('Reset verification failed.'));
    }
    // Ledger is already locked here; inspect the file directly, not Mekari::logs().
    if (jsonFile(ROOT . '/storage/ledger.json') !== []) {
        compat_throw(new RuntimeException('Ledger not empty.'));
    }
    echo json_encode(['status' => 'cleared', 'backup' => str_replace(ROOT . '/', '', $directory), 'counts' => $counts, 'ledger_entries' => 0, 'credentials_preserved' => true, 'scheduler_enabled' => false, 'remote_attendance_deleted' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $release->execute(['name' => $key]);
    flock($ledger, LOCK_UN);
    fclose($ledger);
    flock($dispatch, LOCK_UN);
    fclose($dispatch);
}
