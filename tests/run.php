<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
function check(bool $ok, string $name): void
{
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $name));
    }
    echo 'PASS: ' . $name . PHP_EOL;
}
$validated = Attendance::validate(Attendance::sample(), 'DEMO-001');
check(count($validated['events']) === 2, 'Explicit IN/OUT events preserved');
check(count($validated['issues']) === 2, 'Duplicate and unknown direction rejected');
check(Attendance::validate(Attendance::sample(), 'OTHER')['events'] === [], 'Single NIK isolation');
check(Attendance::validate([['nik' => 'X', 'timestamp' => '2026-02-30T08:00:00+07:00', 'direction' => 'IN']], 'X')['events'] === [], 'Invalid calendar timestamp rejected');
check(Attendance::validate([['nik' => 'X', 'timestamp' => '2099-01-01T08:00:00+07:00', 'direction' => 'IN']], 'X')['events'] === [], 'Future events rejected');
check(str_starts_with(Attendance::csv($validated['events']), "NIK,Timestamp,Direction\r\n"), 'Canonical preview CSV');
check(str_contains(Mekari::authorization('Wed, 10 Nov 2021 07:24:29 GMT', 'YOUR_MEKARI_API_CLIENT_ID', 'YOUR_MEKARI_CLIENT_SECRET'), 'headers="date request-line"'), 'HMAC signed headers');
$expected = base64_encode(hash_hmac('sha256', "date: Wed, 10 Nov 2021 07:24:29 GMT\nPOST /v2/talenta/v2/attendance/import-fingerprint HTTP/1.1", 'test-secret', true));
check(str_contains(Mekari::authorization('Wed, 10 Nov 2021 07:24:29 GMT', 'test-id', 'test-secret'), $expected), 'Endpoint and method included in HMAC');
check(!Mekari::ready(), 'Live requests disabled in default configuration');
try {
    Mekari::submit($validated['events']);
    check(false, 'Must refuse disabled API Trial');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'locked'), 'Submit gate blocks direct calls');
}
$contract = jsonFile(ROOT . '/config/postman-contract.example.json');
$validated['events'] = array_map(fn($event) => $event + ['badge_number' => 'EXPLICIT-BADGE'], $validated['events']);
$csv = Mekari::importCsv($validated['events'], $contract);
check(str_starts_with($csv, "badgeno,checktime\r\n") && str_contains($csv, '26/10/2026 08:00'), 'Official Postman CSV example mapping');
try {
    Mekari::importCsv([['nik' => 'X', 'badge_number' => 'OTHER-BADGE', 'timestamp' => '2026-10-26T08:00:31+07:00', 'direction' => 'IN']], $contract);
    check(false, 'Precision guard');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'precision'), 'CSV does not silently drop seconds');
}
try {
    Mekari::importCsv([$validated['events'][0], $validated['events'][0]], $contract);
    check(false, 'CSV collision guard');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'identical'), 'CSV row collisions blocked');
}
echo "All offline checks passed. No API request sent.\n";
