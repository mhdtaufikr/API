<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$base = $argv[1] ?? 'http://127.0.0.1:8088';
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('HTTP settings tests require loopback.'));
}
$pdo = Database::open('settings');
$service = new IntegrationSettings($pdo, env('APP_KEY'));
$original = $service->row();
$count = 0;
function webCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
$jar = ROOT . '/storage/settings-test-' . bin2hex(random_bytes(6)) . '.cookies';
function requestSettings(?array $data = null, string $path = '/settings.php'): array
{
    global $base, $jar;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
    if ($data !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    }
    $body = curl_exec($curl);
    $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if ($body === false) {
        compat_throw(new RuntimeException('Local HTTP settings request failed.'));
    }
    return ['body' => $body, 'http' => $http];
}
function settingsForm(string $body): array
{
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $csrf);
    preg_match('/name="revision" value="([0-9]+)"/', $body, $revision);
    return ['csrf' => $csrf[1] ?? '', 'revision' => $revision[1] ?? ''];
}
$fixtures = ['MEKARI_CLIENT_ID' => 'HTTP-TEST-CLIENT', 'MEKARI_CLIENT_SECRET' => 'HTTP-TEST-SECRET', 'MEKARI_IMPORT_TOKEN' => 'HTTP-TEST-TOKEN'];
$modified = false;
try {
    $page = requestSettings();
    webCheck($page['http'] === 200 && str_contains($page['body'], 'Mekari Credentials'), 'Settings menu responds on local PHP server');
    webCheck(!str_contains($page['body'], env('APP_KEY')), 'Encryption key is absent from HTML');
    foreach (['Machine Receiver', 'Machine Transactions', 'Employee Attendance', 'Employee Mapping', 'Integration Queue', 'Machine Simulator', 'API Response Logs', 'Database Schema'] as $label) {
        webCheck(str_contains($page['body'], $label), 'Sidebar exposes ' . $label);
    }
    if (array_filter(array_intersect_key($original, array_flip(array_values(IntegrationSettings::FIELDS))))) {
        echo "SKIP: Existing user credentials preserved; HTTP write tests not run.\n";
        exit;
    }
    $form = settingsForm($page['body']);
    $bad = requestSettings($fixtures + ['csrf' => 'bad', 'revision' => $form['revision']]);
    webCheck(str_contains($bad['body'], 'Session expired') && $service->row()['revision'] === $original['revision'], 'Invalid CSRF cannot change credentials');
    $response = requestSettings($fixtures + $form);
    $modified = true;
    webCheck($response['http'] === 303, 'Successful save redirects without echoing credentials');
    webCheck($service->values() === $fixtures, 'Form values reach encrypted database settings');
    IntegrationSettings::reset();
    webCheck(env('MEKARI_CLIENT_ID') === $fixtures['MEKARI_CLIENT_ID'] && env('MEKARI_IMPORT_TOKEN') === $fixtures['MEKARI_IMPORT_TOKEN'], 'Existing Mekari service resolves database credentials');
    $fresh = requestSettings();
    foreach ($fixtures as $value) {
        webCheck(!str_contains($fresh['body'], $value), 'Saved credential is never included in returned HTML');
    }
    $stale = requestSettings(['MEKARI_CLIENT_ID' => 'stale'] + $form);
    webCheck(str_contains($stale['body'], 'Settings changed in another session'), 'Stale settings form is rejected');
    $dashboard = requestSettings(null, '/');
    webCheck(str_contains($dashboard['body'], 'Credentials configured') && str_contains($dashboard['body'], 'Outbound locked'), 'Dashboard uses saved credentials while outbound remains locked');
    foreach ($fixtures as $value) {
        webCheck(!str_contains($dashboard['body'], $value), 'Dashboard does not expose credential plaintext');
    }
    $clear = requestSettings(['clear' => array_fill_keys(array_keys($fixtures), 1)] + settingsForm($fresh['body']));
    webCheck($clear['http'] === 303 && !array_filter($service->values()), 'Explicit clear restores the originally empty credential profile');
    $modified = false;
    IntegrationSettings::reset();
    webCheck(!Mekari::ready(), 'HTTP settings changes never enabled or invoked Talenta');
    echo $count . " HTTP settings checks passed. Test credentials cleared; no Talenta request.\n";
} finally {
    if ($modified) {
        $current = $service->values();
        $row = $service->row();
        if ($current && !array_diff(array_values(array_filter($current)), array_values($fixtures))) {
            $service->save([], array_fill_keys(array_keys($fixtures), 1), (int) $row['revision']);
        }
    }
    if (is_file($jar)) {
        unlink($jar);
    }
}
