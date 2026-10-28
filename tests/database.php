<?php

declare (strict_types=1);
$testNik = 'DB-TEST-' . bin2hex(random_bytes(4));
putenv('ATTENDANCE_TEST_NIK=' . $testNik);
require __DIR__ . '/../app/bootstrap.php';
if (env('DB_DATABASE') === '') {
    echo "SKIP: Configure local database credentials first.\n";
    exit;
}
if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing tests against a non-local database.\n");
    exit(1);
}
$checks = 0;
function dbCheck(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) {
        compat_throw(new RuntimeException($name));
    }
    $checks++;
    echo 'PASS: ' . $name . PHP_EOL;
}
$pdo = null;
try {
    $pdo = Database::open();
    $repository = new AttendanceRepository($pdo);
    $repository->assertInstalled();
    $pdo->beginTransaction();
    $card = (string) random_int(8000, 9999);
    $date = (new DateTimeImmutable('yesterday'))->setTime(7, 25, 0);
    $input = ['cardno' => '9980002T' . $card . $date->format('YmdHis') . '10', 'd' => $date->format('Y/m/d'), 'h' => $date->format('H:i'), 'm' => '998'];
    $event = (new MachineRequestParser())->parse($input);
    if ($repository->findByHash($event['event_hash'])) {
        compat_throw(new RuntimeException('Random test event collision; run again.'));
    }
    $first = $repository->store($event, $input, '127.0.0.1');
    $second = $repository->store($event, $input, '127.0.0.1');
    dbCheck(!$first['duplicate'] && $second['duplicate'] && $first['id'] === $second['id'], 'Real database duplicate detection returns the same row');
    $stored = $repository->findByHash($event['event_hash']);
    dbCheck($stored['raw_message'] === $input['cardno'] && json_decode($stored['request_payload'], true) === $input, 'Raw request and JSON payload survive database round-trip');
    dbCheck((new DateTimeImmutable($stored['event_datetime']))->getTimestamp() === $date->getTimestamp(), 'Stored UTC timestamp preserves Jakarta tap instant');
    $input['cardno'] = '9980002T' . $card . $date->setTime(16, 28)->format('YmdHis') . '20';
    $input['h'] = '16:28';
    $repository->store((new MachineRequestParser())->parse($input), $input, '127.0.0.1');
    $code = 'TEST-' . bin2hex(random_bytes(4));
    $repository->configure('shift', ['code' => $code, 'timezone' => 'Asia/Jakarta', 'start_time' => '07:30', 'end_time' => '16:30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'in_before_minutes' => 30, 'in_after_minutes' => 30, 'out_before_minutes' => 30, 'out_after_minutes' => 30]);
    (new AttendanceEngineRepository($pdo))->configure('shift-policy', ['code' => $code, 'late_grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'duplicate_tap_seconds' => 0, 'window_fallback_enabled' => false, 'minute_rounding' => 'floor', 'grace_mode' => 'threshold']);
    $repository->configure('terminal', ['terminal_id' => '998', 'direction_map' => ['10' => 'IN', '20' => 'OUT']]);
    $mapping = ['card_number' => $card, 'employee_nik' => $testNik, 'employee_name' => 'Database Test Fixture', 'talenta_employee_id' => 'TEST-EMPLOYEE', 'talenta_user_id' => 'TEST-USER', 'badge_number' => 'TEST-BADGE', 'shift_code' => $code, 'verified' => true];
    $repository->configure('employee-map', $mapping);
    $groups = (new AttendanceProcessor())->process($repository->snapshot($date->format('Y-m-d'), $date->format('Y-m-d')), $date->format('Y-m-d'), $date->format('Y-m-d'), $testNik);
    $selected = array_values(array_filter($groups, fn($g) => $g['card_number'] === $card));
    dbCheck(count($selected) === 1 && $selected[0]['status'] === 'ready_for_review' && count($selected[0]['events']) === 2, 'Real shift, direction and employee mapping produce reviewable attendance');
    $group = $selected[0];
    (new AttendanceEngineRepository($pdo))->persist($group);
    dbCheck($repository->enqueue($group), 'Valid configured test employee with persisted attendance enters review queue');
    dbCheck(!$repository->enqueue($group), 'Duplicate queue batch is idempotent');
    $overlap = $group;
    $overlap['batch_hash'] = hash('sha256', $group['batch_hash'] . 'changed');
    dbCheck(!$repository->enqueue($overlap), 'Queue batches cannot claim previously queued raw events');
    $other = $group;
    $other['employee_nik'] = 'NOT-TEST';
    dbCheck(!$repository->enqueue($other), 'Repository independently enforces the single test NIK');
    $queue = array_values(array_filter($repository->queue(), fn($q) => $q['employee_nik'] === $testNik));
    $id = (int) $queue[0]['id'];
    $preview = (new TalentaApiService($repository))->preview($id);
    dbCheck(str_contains($preview['csv'], 'TEST-BADGE') && !str_contains($preview['csv'], $testNik), 'Queue generates mapped Talenta CSV without API credentials');
    $repository->reserve($id);
    dbCheck($repository->queueEntry($id)['status'] === 'reserved', 'Queue reservation persists before any API request');
    try {
        $repository->reserve($id);
        compat_throw(new LogicException('Second reservation unexpectedly succeeded.'));
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) {
            compat_throw($error);
        }
        dbCheck(true, 'Second queue reservation is rejected');
    }
    $repository->finish($id, ['status' => 'submitted_pending_reconciliation', 'http' => 200, 'response' => 'TEST FIXTURE - no network call']);
    $repository->reconcile($id, true, 'Test-only verification reference');
    dbCheck($repository->queueEntry($id)['status'] === 'confirmed', 'Manual reconciliation is recorded separately from HTTP acceptance');
    $reader = Database::open('reader');
    $table = Database::driver() === 'mysql' ? '`' . env('DB_DATABASE') . '`.schema_version' : 'mkm_attendance.schema_version';
    try {
        $reader->exec('UPDATE ' . $table . ' SET version=version WHERE version=0');
        compat_throw(new LogicException('Reader unexpectedly accepted a write.'));
    } catch (PDOException $_ignored) {
        dbCheck(true, 'Read connection rejects writes');
    }
    $pdo->rollBack();
    echo $checks . ' ' . Database::driver() . " integration checks passed; fixtures rolled back, no Talenta request sent.\n";
} catch (Throwable $error) {
    if (($pdo !== null ? $pdo->inTransaction() : null)) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . ($error instanceof PDOException ? 'Database query failed (SQLSTATE ' . $error->getCode() . ').' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
