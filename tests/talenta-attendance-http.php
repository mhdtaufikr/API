<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$base = $argv[1] ?? 'http://localhost/API/public';
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
    compat_throw(new RuntimeException('Local HTTP tests only.'));
}
$count = 0;
function attendanceWebCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
$jar = ROOT . '/storage/attendance-read-test-' . bin2hex(random_bytes(6)) . '.cookies';
$request = function (?array $post = null) use ($base, $jar): array {
    $curl = curl_init(rtrim($base, '/') . '/talenta-attendance.php');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 10]);
    if ($post !== null) {
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    $body = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    if (!is_string($body)) {
        compat_throw(new RuntimeException('Local page unavailable.'));
    }
    return [$code, $body];
};
try {
    $audit = ROOT . '/storage/talenta-reads.jsonl';
    $before = is_file($audit) ? hash_file('sha256', $audit) : null;
    [$code, $html] = $request();
    attendanceWebCheck($code === 200 && str_contains($html, 'Tarik Absensi Talenta'), 'Sidebar page is available');
    attendanceWebCheck(str_contains($html, 'Halaman 1'), 'Shared sidebar does not overwrite pagination state');
    attendanceWebCheck(str_contains($html, date('Y-m-d')), 'Date defaults to current Jakarta date');
    foreach (['MEKARI_CLIENT_ID', 'MEKARI_CLIENT_SECRET', 'MEKARI_IMPORT_TOKEN', 'APP_KEY'] as $key) {
        $value = env($key);
        attendanceWebCheck($value === '' || !str_contains($html, $value), $key . ' is absent from page HTML');
    }
    preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match);
    [$code, $bad] = $request(['csrf' => 'bad', 'from' => date('Y-m-d'), 'to' => date('Y-m-d')]);
    attendanceWebCheck(str_contains($bad, 'Sesi berakhir'), 'Invalid CSRF is rejected before any remote call');
    [$code, $bad] = $request(['csrf' => $match[1], 'from' => '2026-02-30', 'to' => date('Y-m-d')]);
    attendanceWebCheck(str_contains($bad, 'Invalid attendance date range'), 'Invalid date is rejected before any remote call');
    clearstatcache();
    $after = is_file($audit) ? hash_file('sha256', $audit) : null;
    attendanceWebCheck($before === $after, 'Opening the menu and rejected forms do not request remote attendance');
    echo $count . " HTTP reader checks passed. No API call by this test.\n";
} finally {
    if (is_file($jar)) {
        unlink($jar);
    }
}
