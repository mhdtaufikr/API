<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
function verifyLegacy(bool $ok, string $message): void
{
    if (!$ok) {
        compat_throw(new RuntimeException($message));
    }
    echo 'PASS: ' . $message . PHP_EOL;
}
if (($argv[1] ?? '') !== 'child') {
    if (Database::driver() !== 'mysql' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
        compat_throw(new RuntimeException('Local MySQL tests only.'));
    }
    $admin = Database::open();
    $name = 'mkm_config_test_' . bin2hex(random_bytes(6));
    $quoted = Database::quote($name);
    $admin->exec('CREATE DATABASE ' . $quoted);
    try {
        putenv('DB_DATABASE=' . $name);
        putenv('INTEGRATION_STORAGE=legacy_config');
        $process = proc_open([PHP_BINARY, __FILE__, 'child'], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, ROOT);
        if (!is_resource($process) || proc_close($process) !== 0) {
            compat_throw(new RuntimeException('Isolated migration tests failed.'));
        }
    } finally {
        $admin->exec('DROP DATABASE ' . $quoted);
    }
    exit;
}
if (!preg_match('/\Amkm_config_test_[a-f0-9]{12}\z/', env('DB_DATABASE'))) {
    compat_throw(new RuntimeException('Test database required.'));
}
$pdo = Database::open();
foreach (['tbl_rawdata', 'tbl_absen', 'tbl_log'] as $name) {
    $pdo->exec('CREATE TABLE `' . $name . '` (id INT PRIMARY KEY, evidence VARCHAR(50))');
    $pdo->exec("INSERT INTO `" . $name . "` VALUES (1,'original-record')");
}
$snapshot = function () use ($pdo): array {
    $data = [];
    foreach (['tbl_rawdata', 'tbl_absen', 'tbl_log'] as $name) {
        $data[$name] = [$pdo->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM), $pdo->query('SELECT * FROM `' . $name . '`')->fetchAll(PDO::FETCH_ASSOC)];
    }
    return $data;
};
$before = $snapshot();
IntegrationMigrations::run();
IntegrationMigrations::run();
verifyLegacy(count($pdo->query('SHOW TABLES')->fetchAll()) === 4, 'Migration creates only tbl_config and can run twice');
verifyLegacy($before === $snapshot(), 'Legacy table definitions and rows remain identical');
$key = 'base64:' . base64_encode(random_bytes(32));
$service = new IntegrationSettings($pdo, $key);
verifyLegacy($service->seed() && !$service->seed(), 'Config seed is idempotent');
$service->save(['MEKARI_CLIENT_ID' => 'TEST-CLIENT', 'MEKARI_CLIENT_SECRET' => 'TEST-SECRET', 'MEKARI_IMPORT_TOKEN' => 'TEST-TOKEN'], [], 1);
verifyLegacy($service->values()['MEKARI_CLIENT_SECRET'] === 'TEST-SECRET', 'Application reads encrypted credentials from tbl_config');
verifyLegacy(!str_contains(json_encode($service->row()), 'TEST-SECRET'), 'Plain credentials are absent from stored row and audit');
verifyLegacy(count(json_decode($service->row()['audit_history'], true)) === 2, 'Seed/update audit stored in same table');
try {
    $service->save(['MEKARI_CLIENT_SECRET' => 'STALE'], [], 1);
    compat_throw(new LogicException('Stale revision accepted'));
} catch (RuntimeException $error) {
    verifyLegacy(!$error instanceof LogicException, 'Stale update is rejected');
}
verifyLegacy($before === $snapshot(), 'Saving credentials leaves legacy data unchanged');
$pdo->exec("ALTER TABLE tbl_config COMMENT='unrelated-table'");
try {
    IntegrationMigrations::run();
    compat_throw(new LogicException('Unowned table accepted'));
} catch (RuntimeException $error) {
    verifyLegacy(!$error instanceof LogicException, 'Existing unowned tbl_config is refused');
}
verifyLegacy($before === $snapshot(), 'Refused migration leaves legacy data unchanged');
echo "No production access or Talenta request. Isolated test database is removed by parent.\n";
