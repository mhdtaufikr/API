<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$count = 0;
function settingsCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function settingsRefuses(callable $call, string $label): void
{
    try {
        $call();
    } catch (RuntimeException|InvalidArgumentException $_ignored) {
        settingsCheck(true, $label);
        return;
    }
    compat_throw(new RuntimeException('FAIL: ' . $label));
}
$key = 'base64:' . base64_encode(random_bytes(32));
$cipher = new CredentialCipher($key);
$plain = 'TEST-ONLY-' . bin2hex(random_bytes(8));
$a = $cipher->encrypt($plain, 'MEKARI_CLIENT_SECRET');
$b = $cipher->encrypt($plain, 'MEKARI_CLIENT_SECRET');
settingsCheck($a !== $b && !str_contains($a, $plain), 'Random IVs hide equal plaintext and stored values');
settingsCheck($cipher->decrypt($a, 'MEKARI_CLIENT_SECRET') === $plain, 'AES-GCM credential round-trip');
settingsRefuses(fn() => $cipher->decrypt($a, 'MEKARI_IMPORT_TOKEN'), 'Ciphertext cannot be moved to a different credential field');
settingsRefuses(fn() => (new CredentialCipher('base64:' . base64_encode(random_bytes(32))))->decrypt($a, 'MEKARI_CLIENT_SECRET'), 'Wrong encryption key is rejected');
$bytes = base64_decode(substr($a, 3));
$bytes[28] = chr(ord($bytes[28]) ^ 1);
settingsRefuses(fn() => $cipher->decrypt('v1:' . base64_encode($bytes), 'MEKARI_CLIENT_SECRET'), 'Ciphertext tampering is rejected');
settingsRefuses(fn() => new CredentialCipher(''), 'Missing encryption key is rejected');
$pdo = null;
try {
    $pdo = Database::open('settings');
    $pdo->beginTransaction();
    // Use the real application key so existing settings are verified without ever displaying them.
    $service = new IntegrationSettings($pdo, env('APP_KEY'));
    $original = $service->row();
    settingsCheck($original !== null, 'Seeded Talenta profile exists');
    $fixture = ['MEKARI_CLIENT_ID' => 'TEST-ONLY-CLIENT', 'MEKARI_CLIENT_SECRET' => 'TEST-ONLY-SECRET', 'MEKARI_IMPORT_TOKEN' => 'TEST-ONLY-TOKEN'];
    $service->save($fixture, [], (int) $original['revision']);
    $saved = $service->row();
    settingsCheck($service->values() === $fixture, 'Database settings decrypt correctly');
    foreach (IntegrationSettings::FIELDS as $field => $column) {
        settingsCheck(!str_contains($saved[$column], $fixture[$field]), $field . ' stored encrypted');
    }
    settingsCheck(!$service->seed() && $service->row() === $saved, 'Repeated seed preserves existing encrypted credentials');
    settingsRefuses(fn() => $service->save(['MEKARI_CLIENT_ID' => 'STALE'], [], (int) $original['revision']), 'Stale form revision cannot overwrite newer settings');
    settingsRefuses(fn() => $service->save(['MEKARI_CLIENT_ID' => "bad\r\nvalue"], [], (int) $saved['revision']), 'Credential header/control injection rejected');
    settingsRefuses(fn() => $service->save(['MEKARI_IMPORT_TOKEN' => 'replacement'], ['MEKARI_IMPORT_TOKEN' => 1], (int) $saved['revision']), 'Replace and clear cannot conflict');
    $service->save(['MEKARI_CLIENT_ID' => 'REPLACED-CLIENT'], [], (int) $saved['revision']);
    settingsCheck($service->values()['MEKARI_CLIENT_SECRET'] === $fixture['MEKARI_CLIENT_SECRET'], 'Blank secret field retains its original value');
    $row = $service->row();
    $service->save([], ['MEKARI_IMPORT_TOKEN' => 1], (int) $row['revision']);
    settingsCheck($service->values()['MEKARI_IMPORT_TOKEN'] === '', 'Explicit clear removes a credential');
    $audit = $pdo->query('SELECT action,changed_fields FROM ' . IntegrationMigrations::table('integration_settings_audit'))->fetchAll(PDO::FETCH_ASSOC);
    settingsCheck(!str_contains(json_encode($audit), $fixture['MEKARI_CLIENT_SECRET']) && !str_contains(json_encode($audit), $fixture['MEKARI_IMPORT_TOKEN']), 'Settings audit never contains credential values');
    $pdo->rollBack();
    IntegrationSettings::reset();
    settingsCheck(!Mekari::ready(), 'Credential configuration tests do not enable API Trial');
    echo $count . " credential checks passed. Test updates rolled back; no live API request.\n";
} catch (Throwable $error) {
    if (($pdo !== null ? $pdo->inTransaction() : null)) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . ($error instanceof PDOException ? 'Settings database check failed.' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
