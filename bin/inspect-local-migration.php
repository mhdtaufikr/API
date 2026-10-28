<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('Local target only.'));
}
$pdo = Database::connect();
try {
    $rows = $pdo->query('SELECT id,card_number,terminal_id,direction_code,event_datetime FROM attendance_raw_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['raw_events' => $rows, 'direction_counts' => $pdo->query('SELECT direction_code,COUNT(*) AS total FROM attendance_raw_events GROUP BY direction_code')->fetchAll(PDO::FETCH_ASSOC), 'profile_count' => $pdo->query('SELECT COUNT(*) FROM integration_settings')->fetchColumn()], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $pdo->rollBack();
}
