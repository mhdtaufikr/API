<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$passed = 0;
function verify(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $name));
    }
    $passed++;
    echo 'PASS: ' . $name . PHP_EOL;
}
function refuses(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (InvalidArgumentException|DomainException $_ignored) {
        verify(true, $name);
        return;
    }
    compat_throw(new RuntimeException('FAIL: ' . $name));
}
$sample = ['cardno' => '0040002T21202026102509403610', 'd' => '2026/10/25', 'h' => '09:40', 'm' => '004'];
$parser = new MachineRequestParser();
$parsed = $parser->parse($sample);
verify($parsed['card_number'] === '2120' && $parsed['terminal_id'] === '004' && $parsed['direction_code'] === '10' && $parsed['event_datetime'] === '2026-10-25T09:40:36+07:00', 'Supplied raw sample decoded and original precision preserved');
verify($parsed['raw_message'] === $sample['cardno'], 'Original raw message preserved');
$lowercase = $sample;
$lowercase['cardno'] = str_replace('0002T', '0002t', $sample['cardno']);
verify($parser->parse($lowercase)['card_number'] === '2120', 'Actual lowercase 0002t terminal literal accepted');
$alternate = $sample;
$alternate['cardno'] = '005' . substr($sample['cardno'], 3);
$alternate['m'] = '005';
verify($parser->parse($alternate)['terminal_id'] === '005', 'Different terminal decoded without inventing new fields');
foreach ([['d' => '2026/10/24'], ['h' => '09:41'], ['m' => '999'], ['cardno' => 'broken'], ['cardno' => '0040002T21202026023009403610'], ['cardno' => '0040003T21202026102509403610'], ['cardno' => ['x']], ['extra' => 'x']] as $i => $change) {
    refuses(fn() => $parser->parse(array_replace($sample, $change)), 'Invalid/mismatched sample rejected #' . ($i + 1));
}
refuses(fn() => $parser->parse(['cardno' => $sample['cardno']]), 'Missing GET fields rejected');
$server = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1', 'QUERY_STRING' => http_build_query($sample)];
ReceiverHttp::validateTransport($server, $sample);
verify(true, 'Allowed GET transport');
refuses(fn() => ReceiverHttp::validateTransport(array_replace($server, ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']), $sample), 'Forwarded header cannot bypass source IP allowlist');
refuses(fn() => ReceiverHttp::validateTransport(array_replace($server, ['REQUEST_METHOD' => 'POST']), $sample), 'Undefined POST ingestion rejected');
refuses(fn() => ReceiverHttp::validateTransport(array_replace($server, ['QUERY_STRING' => str_repeat('x', 1025)]), $sample), 'Query size bounded');
refuses(fn() => ReceiverHttp::validateTransport(array_replace($server, ['QUERY_STRING' => $server['QUERY_STRING'] . '&m=004']), $sample), 'Repeated GET keys rejected');
refuses(fn() => ReceiverHttp::validateTransport(array_replace($server, ['HTTP_SEC_FETCH_SITE' => 'cross-site']), $sample), 'Cross-site browser injection blocked');
final class MemoryReceiverStore implements ReceiverEventStore
{
    public array $events = [];
    public function store(array $event, array $payload, string $sourceIp): array
    {
        $duplicate = isset($this->events[$event['event_hash']]);
        $this->events[$event['event_hash']] = $event;
        return ['id' => 1, 'duplicate' => $duplicate];
    }
}
final class BrokenReceiverStore implements ReceiverEventStore
{
    public function store(array $event, array $payload, string $sourceIp): array
    {
        compat_throw(new PDOException('Simulated unavailable database'));
    }
}
$path = ROOT . '/storage/test-receiver-' . bin2hex(random_bytes(6)) . '.jsonl';
$audit = new ReceiverAudit($path);
try {
    $memory = new MemoryReceiverStore();
    $receiver = new MachineReceiverService($memory, $parser, $audit);
    $first = $receiver->receive($sample, '127.0.0.1', str_repeat('a', 24));
    $second = $receiver->receive($sample, '127.0.0.1', str_repeat('b', 24));
    verify($first['http'] === 200 && $first['status'] === 'stored', 'Valid GET event reaches storage adapter');
    verify($second['http'] === 200 && $second['status'] === 'duplicate' && count($memory->events) === 1, 'Duplicate delivery preserves one stored event');
    verify($receiver->receive(array_replace($sample, ['cardno' => 'broken']), '127.0.0.1', str_repeat('c', 24))['http'] === 422, 'Malformed message rejected and audited');
    $failed = (new MachineReceiverService(new BrokenReceiverStore(), $parser, $audit))->receive($sample, '127.0.0.1', str_repeat('d', 24));
    verify($failed['http'] === 503 && $audit->summary()['error'] === 1, 'Database failure is controlled and logged without success acknowledgement');
    verify($audit->summary()['duplicate'] === 1 && $audit->summary()['rejected'] === 1, 'Audit includes duplicate and rejection statistics');
    verify($audit->summary(str_repeat('d', 24))['entry']['payload']['cardno'] === $sample['cardno'], 'Debugging payload preserved on database failure');
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}
function event(int $id, string $time, string $direction = '10', string $terminal = '004'): array
{
    return ['id' => $id, 'card_number' => '2120', 'terminal_id' => $terminal, 'direction_code' => $direction, 'event_datetime' => $time];
}
$fixture = ['mappings' => [['card_number' => '2120', 'employee_nik' => 'TEST-NIK', 'employee_name' => 'Test Employee', 'talenta_employee_id' => 'EMPLOYEE-123', 'talenta_user_id' => 'USER-456', 'badge_number' => 'BADGE-789', 'shift_code' => 'DAY', 'verified' => true]], 'shifts' => [['code' => 'DAY', 'timezone' => 'Asia/Jakarta', 'start_time' => '07:30', 'end_time' => '16:30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'in_before_minutes' => 30, 'in_after_minutes' => 30, 'out_before_minutes' => 30, 'out_after_minutes' => 30]], 'terminals' => [['terminal_id' => '004', 'direction_map' => ['10' => 'IN', '20' => 'OUT']]], 'rows' => [event(1, '2026-10-26T07:25:00+07:00'), event(2, '2026-10-26T16:28:00+07:00', '20')]];
$fixture['statuses'] = jsonFile(ROOT . '/config/attendance-statuses.seed.json');
$fixture['shifts'][0] += ['late_grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'duplicate_tap_seconds' => 120, 'window_fallback_enabled' => false, 'minute_rounding' => 'floor', 'grace_mode' => 'threshold'];
$processor = new AttendanceProcessor();
$process = fn($f) => $processor->process($f, '2026-10-26', '2026-10-27', 'TEST-NIK');
$group = $process($fixture)[0];
verify($group['status'] === 'ready_for_review' && str_contains($group['clock_in'], '07:25') && str_contains($group['clock_out'], '16:28'), 'Two explicitly directed taps produce shift-aware preview');
verify($group['events'][0]['nik'] === 'TEST-NIK' && $group['events'][0]['badge_number'] === 'BADGE-789', 'Card, NIK, user, employee and badge remain explicit distinct mappings');
$other = $processor->process($fixture, '2026-10-26', '2026-10-27', 'ANOTHER-NIK')[0];
verify($other['status'] === 'local_only', 'Other employees cannot enter trial queue');
$changed = $fixture;
$changed['rows'][1]['direction_code'] = '10';
verify($process($changed)[0]['status'] === 'manual_review', 'Two same-direction taps are not inferred as clock in/out');
$changed = $fixture;
$changed['rows'][] = event(3, '2026-10-26T07:26:00+07:00');
verify($process($changed)[0]['status'] === 'ready_for_review' && count($process($changed)[0]['events']) === 2, 'Repeated tap excluded by explicitly configured duplicate window');
$changed = $fixture;
array_pop($changed['rows']);
verify(in_array('Missing clock-out', $process($changed)[0]['issues'], true), 'Missing clock-out flagged');
$changed = $fixture;
array_shift($changed['rows']);
verify(in_array('Missing clock-in', $process($changed)[0]['issues'], true), 'Missing clock-in flagged');
$changed = $fixture;
$changed['rows'][1]['direction_code'] = '99';
verify($process($changed)[0]['status'] === 'manual_review', 'Unknown direction requires review');
$changed = $fixture;
$changed['mappings'] = [];
verify($process($changed)[0]['status'] === 'manual_review', 'Unmapped card is held locally');
$changed = $fixture;
$changed['shifts'] = [];
verify($process($changed)[0]['status'] === 'manual_review', 'Unknown shift never infers earliest/latest tap');
$changed = $fixture;
$changed['mappings'][0]['verified'] = false;
verify($process($changed)[0]['status'] === 'manual_review', 'Unverified identity cannot enter queue');
$changed = $fixture;
$changed['rows'][0]['event_datetime'] = '2026-10-26T12:00:00+07:00';
verify($process($changed)[0]['status'] === 'manual_review', 'Direction-specific shift window enforced');
$night = $fixture;
$night['shifts'][0]['start_time'] = '22:00';
$night['shifts'][0]['end_time'] = '06:00';
$night['rows'] = [event(4, '2026-10-26T21:55:00+07:00'), event(5, '2026-10-27T06:03:00+07:00', '20')];
$group = $process($night)[0];
verify($group['status'] === 'ready_for_review' && $group['shift_date'] === '2026-10-26' && str_contains($group['clock_out'], '2026-10-27'), 'Overnight shift retains prior shift date');
$csvContract = ['csv_columns' => ['badgeno' => ['source' => 'badge'], 'checktime' => ['source' => 'checktime', 'format' => 'd/m/Y H:i:s']]];
$csv = Mekari::importCsv($group['events'], $csvContract);
verify(str_contains($csv, 'BADGE-789') && !str_contains($csv, 'TEST-NIK'), 'Import CSV uses mapped badge, not NIK/card, without credentials');
verify(!Mekari::ready(), 'All receiver and processing tests ran with outbound disabled');
echo $passed . " machine checks passed. In-memory store used only for unit tests; no PostgreSQL persistence claim.\n";
