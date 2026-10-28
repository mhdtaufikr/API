<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    compat_throw(new RuntimeException('Only local integration tests are allowed.'));
}
$checks = 0;
function persistedCheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
$pdo = Database::open();
$engine = new AttendanceEngineRepository($pdo);
$engine->requireInstalled();
$source = new AttendanceRepository($pdo);
$processor = new AttendanceProcessor();
$now = new DateTimeImmutable('2026-10-30T12:00:00+07:00');
$pdo->beginTransaction();
try {
    $id = bin2hex(random_bytes(4));
    $nik = 'ENGINE-TEST-' . $id;
    $card = (string) random_int(8000, 9999);
    $code = 'ENG-' . $id;
    $source->configure('shift', ['code' => $code, 'timezone' => 'Asia/Jakarta', 'start_time' => '07:30', 'end_time' => '16:30', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'in_before_minutes' => 60, 'in_after_minutes' => 60, 'out_before_minutes' => 60, 'out_after_minutes' => 60]);
    $engine->configure('shift-policy', ['code' => $code, 'late_grace_minutes' => 0, 'early_leave_grace_minutes' => 0, 'duplicate_tap_seconds' => 60, 'window_fallback_enabled' => false, 'minute_rounding' => 'floor', 'grace_mode' => 'threshold']);
    $source->configure('employee-map', ['card_number' => $card, 'employee_nik' => $nik, 'employee_name' => 'Attendance engine test fixture', 'shift_code' => $code, 'verified' => false]);
    $source->configure('terminal', ['terminal_id' => '997', 'direction_map' => ['10' => 'IN', '20' => 'OUT']]);
    $parser = new MachineRequestParser();
    foreach ([['20261020073000', '07:30', '10'], ['20261020163000', '16:30', '20']] as [$stamp, $hour, $direction]) {
        $input = ['cardno' => '9970002T' . $card . $stamp . $direction, 'd' => '2026/10/20', 'h' => $hour, 'm' => '997'];
        $event = $parser->parse($input);
        if ($source->findByHash($event['event_hash'])) {
            compat_throw(new RuntimeException('Random test card collision; rerun.'));
        }
        $source->store($event, $input, '127.0.0.1');
    }
    $raw = function () use ($pdo, $card): string {
        $stmt = $pdo->prepare('SELECT * FROM ' . IntegrationMigrations::table('attendance_raw_events') . ' WHERE card_number=:card ORDER BY id');
        $stmt->execute(['card' => $card]);
        return hash('sha256', json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_THROW_ON_ERROR));
    };
    $beforeRaw = $raw();
    $calculate = fn($date) => $processor->calculate($source->snapshot($date, $date, $nik), $date, $date, $now)[0];
    $record = $calculate('2026-10-20');
    persistedCheck(!$engine->exportable($record), 'Unprocessed raw attendance cannot bypass the persisted-record export gate');
    persistedCheck($engine->persist($record, true) === 'would_created' && $engine->find($nik, '2026-10-20') === null, 'Dry run predicts creation without inserting a record');
    persistedCheck($engine->persist($record) === 'created', 'Attendance record persisted in real local database');
    $first = $engine->find($nik, '2026-10-20');
    persistedCheck($first['final_attendance_status'] === 'PRESENT' && (int) $first['work_minutes'] === 540, 'Persisted result contains schedule-derived status and work minutes');
    persistedCheck($engine->exportable($record), 'Current unprotected persisted attendance is eligible for explicit queue preparation');
    persistedCheck($engine->persist($calculate('2026-10-20')) === 'skipped_unchanged' && $engine->find($nik, '2026-10-20')['id'] === $first['id'], 'Reprocessing same employee/date is idempotent');
    $stmt = $pdo->prepare('SELECT raw_event_id,classification FROM ' . IntegrationMigrations::table('attendance_sources') . ' WHERE attendance_id=:id');
    $stmt->execute(['id' => $first['id']]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    persistedCheck(count($links) === 2 && array_column($links, 'classification') === ['clock_in', 'clock_out'], 'Persistent source-log relationships identify separate IN and OUT');
    $engine->configure('attendance-protect', ['employee_nik' => $nik, 'attendance_date' => '2026-10-20', 'reason' => 'Test HR correction', 'values' => ['actual_clock_in' => '2026-10-20T07:35:00+07:00', 'late_minutes' => 5, 'final_attendance_status' => 'LATE']]);
    $protected = $engine->find($nik, '2026-10-20');
    persistedCheck($engine->persist($record) === 'skipped_protected' && $engine->find($nik, '2026-10-20') === $protected, 'HR manual correction is never overwritten by reprocessing');
    persistedCheck(!$engine->exportable($record), 'HR-protected attendance cannot enter automatic raw-event export');
    $engine->configure('attendance-request', ['reference' => 'REQUEST-' . $id, 'employee_nik' => $nik, 'attendance_date' => '2026-10-21', 'state' => 'approved', 'approved_values' => ['actual_clock_in' => '2026-10-21T07:30:00+07:00', 'actual_clock_out' => '2026-10-21T16:30:00+07:00', 'late_minutes' => 0, 'early_leave_minutes' => 0, 'work_minutes' => 540, 'final_attendance_status' => 'PRESENT']]);
    $approvedRecord = $calculate('2026-10-21');
    $engine->persist($approvedRecord);
    $approved = $engine->find($nik, '2026-10-21');
    persistedCheck($approved['raw_attendance_status'] === 'ABSENT' && $approved['final_attendance_status'] === 'PRESENT' && $approved['final_status_source'] === 'approved_request', 'Approved request preserves raw status and controls final attendance');
    persistedCheck($engine->persist($approvedRecord) === 'skipped_protected' && $engine->find($nik, '2026-10-21') === $approved, 'Approved attendance values are protected on reprocessing');
    $engine->configure('attendance-period-lock', ['employee_nik' => $nik, 'from_date' => '2026-10-22', 'to_date' => '2026-10-22', 'locked' => true, 'reason' => 'Test locked period']);
    persistedCheck($engine->persist($calculate('2026-10-22')) === 'skipped_locked' && $engine->find($nik, '2026-10-22') === null, 'Locked period prevents creation and modification');
    $r = $calculate('2026-10-23');
    $engine->persist($r);
    $stmt = $pdo->prepare('UPDATE ' . IntegrationMigrations::table('attendances') . " SET is_confirmed=TRUE,final_status_source='confirmed' WHERE employee_nik=:nik AND attendance_date=:date");
    $stmt->execute(['nik' => $nik, 'date' => '2026-10-23']);
    persistedCheck($engine->persist($r) === 'skipped_protected', 'Confirmed attendance remains protected');
    foreach (['A', 'B'] as $suffix) {
        $engine->configure('attendance-request', ['reference' => 'CONFLICT-' . $id . $suffix, 'employee_nik' => $nik, 'attendance_date' => '2026-10-24', 'state' => 'approved', 'approved_values' => ['final_attendance_status' => 'PRESENT']]);
    }
    $command = new AttendanceProcessingCommand($engine, $source, $processor);
    $summary = $command->run('2026-10-24', '2026-10-25', $nik, false, $now);
    persistedCheck($summary['processing_errors'] === 1 && $summary['attendance_created'] === 1 && $engine->find($nik, '2026-10-25') !== null, 'Individual processing error does not stop subsequent dates');
    $before = [];
    foreach (['attendances', 'attendance_sources', 'attendance_processing_errors', 'attendance_requests', 'attendance_period_locks'] as $table) {
        $before[$table] = $pdo->query('SELECT COUNT(*) FROM ' . IntegrationMigrations::table($table))->fetchColumn();
    }
    $dry = $command->run('2026-10-26', '2026-10-27', $nik, true, $now);
    $after = [];
    foreach (array_keys($before) as $table) {
        $after[$table] = $pdo->query('SELECT COUNT(*) FROM ' . IntegrationMigrations::table($table))->fetchColumn();
    }
    persistedCheck($dry['dry_run'] && $dry['attendance_created'] === 2 && $before === $after, 'Full dry-run command does not mutate database tables');
    $collision = $record;
    $collision['attendance_date'] = '2026-10-29';
    $collision['input_hash'] = hash('sha256', 'collision');
    try {
        $engine->persist($collision);
        compat_throw(new LogicException('Source raw log was claimed by another attendance.'));
    } catch (PDOException $error) {
        persistedCheck($engine->find($nik, '2026-10-29') === null, 'Conflicting raw-log ownership rolls back the entire attendance write');
    }
    persistedCheck($raw() === $beforeRaw, 'Processing, HR corrections and errors never modify original machine logs');
    $engine->configure('attendance-status', ['code' => 'LATE', 'condition_key' => 'late', 'label' => 'Test custom late label', 'priority' => 51]);
    $engine->seedStatuses();
    $status = $pdo->query("SELECT priority FROM " . IntegrationMigrations::table('attendance_statuses') . " WHERE code='LATE'")->fetchColumn();
    persistedCheck((int) $status === 51, 'Status seed preserves existing HR-defined priorities');
    $pdo->rollBack();
    echo $checks . " persisted attendance checks passed. All fixtures rolled back; no Talenta request.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . ($error instanceof PDOException ? 'Attendance SQL error ' . $error->getCode() : $error->getMessage()) . PHP_EOL);
    exit(1);
}
