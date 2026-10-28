<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$count = 0;
function readerCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function readerRejects(callable $call, string $label): void
{
    try {
        $call();
    } catch (InvalidArgumentException|RuntimeException $_ignored) {
        readerCheck(true, $label);
        return;
    }
    compat_throw(new LogicException('FAIL: ' . $label));
}
$filters = TalentaAttendanceReader::filters(['from' => '2026-10-27', 'to' => '2026-10-27', 'page' => '2', 'limit' => '25', 'user_ids' => '123,456', 'search' => 'Test Employee']);
$target = TalentaAttendanceReader::target($filters);
readerCheck(str_starts_with($target, TalentaAttendanceReader::PATH . '?') && str_contains($target, 'user_ids=123%2C456') && str_contains($target, 'query=Test%20Employee'), 'GET query uses documented parameters and RFC3986 encoding');
readerCheck(!str_contains($target, 'token') && !str_contains($target, 'secret') && !str_contains($target, 'client_id'), 'Credential values never enter query parameters');
$date = 'Tue, 27 Oct 2026 01:00:00 GMT';
$expected = base64_encode(hash_hmac('sha256', 'date: ' . $date . "\nGET " . $target . ' HTTP/1.1', 'TEST-SECRET', true));
readerCheck(str_contains(Mekari::signRequest($date, 'TEST-ID', 'TEST-SECRET', 'GET', $target), $expected), 'GET HMAC covers exact method, path and encoded query');
readerCheck(Mekari::authorization($date, 'TEST-ID', 'TEST-SECRET') === Mekari::signRequest($date, 'TEST-ID', 'TEST-SECRET', 'POST', Mekari::PATH), 'Existing import POST signature remains unchanged');
foreach ([['from' => '2026-02-30'], ['from' => '2026-10-28', 'to' => '2026-10-27'], ['from' => '2026-09-01', 'to' => '2026-10-27'], ['page' => 0], ['limit' => 101], ['user_ids' => '2131 OR 1=1'], ['user_ids' => 'https://example.com'], ['search' => 'ab']] as $i => $bad) {
    readerRejects(fn() => TalentaAttendanceReader::filters($bad), 'Invalid filter rejected #' . ($i + 1));
}
$body = ['data' => ['summary_attendance_report' => [['company_id' => '11423', 'user_id' => 123, 'employee_id' => 'TEST-EMPLOYEE', 'full_name' => '<script>alert(1)</script>', 'schedule_date' => '2026-10-27', 'clock_in' => '07:30:00', 'clock_out' => null, 'photo' => 'https://example.com/private', 'token' => 'SHOULD-NOT-EXPOSE']], 'pagination' => ['current_page' => 2, 'last_page' => 3, 'total' => 60, 'next_page_url' => 'https://untrusted.example/']]];
$result = TalentaAttendanceReader::normalize($body, '11423', $filters);
readerCheck(count($result['rows']) === 1 && $result['rows'][0]['clock_in'] === '07:30:00', 'Documented summary response is decoded');
readerCheck(!isset($result['rows'][0]['token'], $result['rows'][0]['photo']) && !str_contains(json_encode($result), 'SHOULD-NOT-EXPOSE'), 'Only whitelisted attendance columns are retained');
readerCheck($result['pagination']['total'] === 60 && !isset($result['pagination']['next_page_url']), 'Pagination keeps numeric metadata without following remote URLs');
readerCheck(!str_contains(e($result['rows'][0]['full_name']), '<script>'), 'Remote HTML content is escaped for rendering');
$empty = $body;
$empty['data']['summary_attendance_report'][0]['clock_in'] = '';
readerCheck(TalentaAttendanceReader::normalize($empty, '11423', $filters)['rows'][0]['clock_in'] === null, 'Empty remote clocks remain missing rather than invented attendance');
$bad = $body;
$bad['data']['summary_attendance_report'][0]['company_id'] = '999';
readerRejects(fn() => TalentaAttendanceReader::normalize($bad, '11423', $filters), 'Wrong-company data is not displayed');
$bad = $body;
$bad['data']['summary_attendance_report'][0]['schedule_date'] = '2026-10-26';
readerRejects(fn() => TalentaAttendanceReader::normalize($bad, '11423', $filters), 'Out-of-range attendance is rejected');
readerRejects(fn() => TalentaAttendanceReader::normalize(['data' => []], '11423', $filters), 'Unexpected response structure is not labeled empty success');
TalentaAttendanceReader::verifyCollection();
readerCheck(true, 'GET summary endpoint verified against workspace Postman collection');
readerCheck(str_contains(TalentaAttendanceReader::errorHint(422, ['user_ids' => '2120']), 'berbeda dari nomor karyawan'), 'Invalid internal user filter explains employee/user identity distinction');
readerCheck(!str_contains(TalentaAttendanceReader::errorHint(422, ['user_ids' => '']), 'credential'), 'Unfiltered 422 does not incorrectly blame credentials');
echo $count . " reader checks passed. No network request or attendance write.\n";
