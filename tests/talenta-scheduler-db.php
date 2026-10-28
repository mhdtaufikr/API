<?php

declare (strict_types=1);
$nik = 'SCHEDULE-TEST-' . bin2hex(random_bytes(4));
putenv('ATTENDANCE_TEST_NIK=' . $nik);
putenv('TALENTA_SCHEDULER_ENABLED=true');
require __DIR__ . '/../app/bootstrap.php';
if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('Local integration tests only.'));
}
$pdo = Database::open();
$pdo->beginTransaction();
$count = 0;
function schedulerDbCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
try {
    $repo = new AttendanceRepository($pdo);
    $parser = new MachineRequestParser();
    $card = (string) random_int(8000, 9999);
    $events = [];
    foreach ([['20261026073000', '07:30', '10', 'IN'], ['20261026163000', '16:30', '20', 'OUT']] as [$stamp, $hour, $code, $direction]) {
        $input = ['cardno' => '9960002T' . $card . $stamp . $code, 'd' => '2026/10/26', 'h' => $hour, 'm' => '996'];
        $raw = $parser->parse($input);
        if ($repo->findByHash($raw['event_hash'])) {
            compat_throw(new RuntimeException('Test fixture collision; run again.'));
        }
        $insert = $repo->store($raw, $input, '127.0.0.1');
        $events[] = ['id' => $insert['id'], 'nik' => $nik, 'timestamp' => $raw['event_datetime'], 'direction' => $direction, 'badge_number' => 'TEST-BADGE'];
    }
    $record = ['employee_nik' => $nik, 'card_number' => $card, 'attendance_date' => '2026-10-26', 'input_hash' => hash('sha256', 'test')];
    $morning = $repo->enqueueScheduled($record, [$events[0]], 'TEST-PROFILE');
    schedulerDbCheck($morning !== null, 'Scheduled morning batch can contain IN only');
    schedulerDbCheck($repo->claimedEvents(array_column($events, 'id')) === [$events[0]['id']], 'Only the morning event is claimed');
    $repo->reserve($morning);
    $repo->finish($morning, ['status' => 'submitted_pending_reconciliation', 'response' => 'TEST ONLY - no API request']);
    $afternoon = $repo->enqueueScheduled($record, [$events[1]], 'TEST-PROFILE');
    schedulerDbCheck($afternoon !== null && $afternoon !== $morning, 'Afternoon OUT enters a separate delta batch');
    schedulerDbCheck(count($repo->claimedEvents(array_column($events, 'id'))) === 2, 'Both individual taps are uniquely claimed across schedules');
    schedulerDbCheck($repo->enqueueScheduled($record, [$events[0]], 'TEST-PROFILE') === null, 'Already scheduled tap cannot be queued again');
    $snapshot = $repo->queueEntry($afternoon)['snapshot'];
    schedulerDbCheck($snapshot['mode'] === 'scheduled_events' && $snapshot['events'][0]['timestamp'] === $events[1]['timestamp'], 'Scheduled snapshot preserves original OUT timestamp');
    $pdo->rollBack();
    echo $count . " scheduled database checks passed. Fixtures rolled back; no API call or scheduler activation.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . ($error instanceof PDOException ? 'Scheduler SQL error ' . $error->getCode() : $error->getMessage()) . PHP_EOL);
    exit(1);
}
