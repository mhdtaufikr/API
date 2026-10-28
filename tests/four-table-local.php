<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || env('INTEGRATION_STORAGE') !== 'legacy_app') {
    compat_throw(new RuntimeException('Local four-table installation required.'));
}
function fourCheck(bool $ok, string $name): void
{
    if (!$ok) {
        compat_throw(new RuntimeException($name));
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
$pdo = Database::open();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
$rawCount = (int) $pdo->query('SELECT COUNT(*) FROM tbl_rawdata')->fetchColumn();
fourCheck($tables === ['tbl_absen', 'tbl_config', 'tbl_log', 'tbl_rawdata'], 'Exactly four approved tables exist');
$repo = new LegacyAttendanceRepository(Database::open('reader'));
fourCheck(count($repo->inspect()) === 6, 'Raw legacy columns retain exact names');
$service = new IntegrationSettings($pdo, env('APP_KEY'));
$before = $service->row();
$values = $service->values();
fourCheck($before !== null && is_array($values), 'Preserved credentials decrypt using original APP_KEY');
fourCheck(!Mekari::ready(), 'Live sending remains disabled');
$pdo->beginTransaction();
try {
    $fixture = 'LOCAL-CHECK-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO tbl_rawdata (FdDate,FcTime,FcCardNo,FcDirection,FcTermNo,FlFlag) VALUES (:date,:time,:card,:direction,:terminal,0)');
    $stmt->execute(['date' => '2026-10-25', 'time' => '07:20:36', 'card' => $fixture, 'direction' => '1', 'terminal' => '004']);
    $rows = (new LegacyAttendanceRepository($pdo))->rows('2026-10-25', '2026-10-25', $fixture);
    fourCheck(count($rows) === 1 && LegacyAttendanceRepository::timestamp($rows[0]) === '2026-10-25T07:20:36+07:00', 'Legacy retrieval preserves original seconds and Jakarta timezone');
    $service->save(['MEKARI_CLIENT_SECRET' => 'LOCAL-ROLLBACK-ONLY'], [], (int) $before['revision']);
    fourCheck($service->values()['MEKARI_CLIENT_SECRET'] === 'LOCAL-ROLLBACK-ONLY', 'Settings write uses tbl_config without obsolete tables');
} finally {
    $pdo->rollBack();
    IntegrationSettings::reset();
}
fourCheck($service->row() === $before, 'Credential and audit changes rolled back');
fourCheck((int) $pdo->query('SELECT COUNT(*) FROM tbl_rawdata')->fetchColumn() === $rawCount, 'Test fixtures rolled back; existing raw records preserved');
$read = Database::open('reader');
try {
    $read->exec("INSERT INTO tbl_log (FcMessage) VALUES ('must-not-write')");
    compat_throw(new LogicException('Reader allowed write'));
} catch (PDOException $_ignored) {
    fourCheck(true, 'Reader connection rejects writes');
}
echo "No API request; local fixture transaction rolled back.\n";
