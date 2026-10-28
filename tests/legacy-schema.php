<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
function legacyCheck(bool $ok, string $name): void
{
    if (!$ok) {
        compat_throw(new RuntimeException($name));
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
if (!in_array($argv[1] ?? '', ['child', 'readonly'], true)) {
    if (Database::driver() !== 'mysql' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
        compat_throw(new RuntimeException('Isolated local MySQL tests only.'));
    }
    $pdo = Database::open();
    $name = 'mkm_legacy_test_' . bin2hex(random_bytes(6));
    $q = Database::quote($name);
    $pdo->exec('CREATE DATABASE ' . $q);
    try {
        putenv('DB_DATABASE=' . $name);
        putenv('INTEGRATION_STORAGE=legacy_local');
        putenv('LEGACY_LOCAL_WRITE=true');
        putenv('LEGACY_DIRECTION_MAP={"10":"1","20":"2"}');
        $p = proc_open([PHP_BINARY, __FILE__, 'child'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, ROOT);
        if (!is_resource($p) || proc_close($p) !== 0) {
            compat_throw(new RuntimeException('Legacy adapter tests failed.'));
        }
    } finally {
        $pdo->exec('DROP DATABASE ' . $q);
    }
    exit;
}
if (!preg_match('/\Amkm_legacy_test_[a-f0-9]{12}\z/', env('DB_DATABASE'))) {
    compat_throw(new RuntimeException('Isolated test database required.'));
}
if (($argv[1] ?? '') === 'readonly') {
    foreach (['base', 'reader', 'worker', 'receiver', 'settings'] as $purpose) {
        $connection = Database::open($purpose);
        try {
            $connection->exec("INSERT INTO tbl_log (FcMessage) VALUES ('must-not-write')");
            compat_throw(new LogicException('Readonly role permitted insert'));
        } catch (PDOException $_ignored) {
            legacyCheck(true, 'Readonly mode rejects writes for ' . $purpose);
        }
    }
    $repo = new LegacyAttendanceRepository(Database::open('reader'));
    legacyCheck(count($repo->rows('2026-10-25', '2026-10-25', '2120')) === 3, 'Read-only mode reads existing raw source');
    legacyCheck(!Mekari::ready(), 'Read-only source cannot submit Talenta');
    exit;
}
$pdo = Database::open('receiver');
foreach (explode(';', preg_replace('/^--.*$/m', '', file_get_contents(ROOT . '/migrations/local_legacy.mysql.sql'))) as $sql) {
    if (trim($sql) !== '') {
        $pdo->exec($sql);
    }
}
$repo = new LegacyAttendanceRepository($pdo);
legacyCheck(count($repo->inspect()) === 6, 'Exact six legacy column names discovered');
$payload = ['cardno' => '0040002T21202026102509403610', 'd' => '2026/10/25', 'h' => '09:40', 'm' => '004'];
$event = (new MachineRequestParser())->parse($payload);
$repo->store($event, $payload, '127.0.0.1');
$rows = $repo->rows('2026-10-25', '2026-10-25', '2120');
legacyCheck(count($rows) === 1 && $rows[0]['FcTime'] === '09:40:36' && (int) $rows[0]['FlFlag'] === 0, 'Local receiver inserts tbl_rawdata with original seconds and flag zero');
legacyCheck(LegacyAttendanceRepository::timestamp($rows[0]) === '2026-10-25T09:40:36+07:00', 'Legacy wall clock interpreted in Jakarta without send-time replacement');
try {
    $repo->store($event, $payload, '127.0.0.1');
    compat_throw(new LogicException('Repeat accepted'));
} catch (RuntimeException $e) {
    legacyCheck(!$e instanceof LogicException, 'Identical tuple replay held as ambiguous without second insertion');
}
$other = $payload;
$other['cardno'] = '005' . substr($payload['cardno'], 3);
$other['m'] = '005';
$repo->store((new MachineRequestParser())->parse($other), $other, '127.0.0.1');
legacyCheck(count($repo->rows('2026-10-25', '2026-10-25', '2120')) === 2, 'Same timestamp on a different terminal preserved');
$pdo->exec('INSERT INTO tbl_rawdata SELECT * FROM tbl_rawdata WHERE FcTermNo=\'004\'');
$rows = $repo->rows('2026-10-25', '2026-10-25', '2120');
legacyCheck(count($rows) === 3 && count(array_filter($rows, fn($r) => $r['identity_ambiguous'])) === 2, 'Existing identical physical rows preserved and flagged ambiguous');
legacyCheck($repo->rows('2026-10-25', '2026-10-25', "2120' OR 1=1 --") === [], 'Parameterized card filter resists SQL injection');
legacyCheck(count($pdo->query('SHOW TABLES')->fetchAll()) === 3, 'No second raw table or integration tables created');
legacyCheck(!Mekari::ready(), 'Legacy API dispatch is blocked independent of live credentials');
try {
    IntegrationMigrations::run();
    compat_throw(new LogicException('Migration accepted'));
} catch (RuntimeException $e) {
    legacyCheck(!$e instanceof LogicException, 'Legacy mode blocks generated migrations');
}
legacyCheck((int) $pdo->query('SELECT SUM(FlFlag) FROM tbl_rawdata')->fetchColumn() === 0, 'FlFlag untouched during compatibility tests');
putenv('INTEGRATION_STORAGE=legacy_readonly');
$child = proc_open([PHP_BINARY, __FILE__, 'readonly'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, ROOT);
if (!is_resource($child) || proc_close($child) !== 0) {
    compat_throw(new RuntimeException('Read-only compatibility tests failed.'));
}
echo "Fixture-only direction map is not a production semantic claim. No Talenta request.\n";
