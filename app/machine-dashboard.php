<?php

// Included from the local, session-protected dashboard only.
$receiverError = '';
$receiverNotice = '';
$transactions = [];
$attendanceGroups = [];
$queue = [];
$queueCounts = [];
$eventCount = null;
$snapshot = null;
$persistedAttendances = [];
$machineFrom = is_string($_POST['machine_from'] ?? null) ? $_POST['machine_from'] : date('Y-m-d');
$machineTo = is_string($_POST['machine_to'] ?? null) ? $_POST['machine_to'] : $machineFrom;
try {
    $auditSummary = (new ReceiverAudit())->summary();
} catch (Throwable $_ignored) {
    $auditSummary = ['received' => 0, 'stored' => 0, 'duplicate' => 0, 'rejected' => 0, 'error' => 0, 'last' => null];
    $receiverError = 'Receiver audit unavailable.';
}
try {
    $repository = new AttendanceRepository(null, ($_POST['action'] ?? '') === 'prepare_queue' ? 'worker' : 'reader');
    $repository->assertInstalled();
    $transactions = $repository->recent();
    $eventCount = $repository->count();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $transactions) {
        $machineFrom = (new DateTimeImmutable($transactions[0]['event_datetime']))->setTimezone(new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta')))->format('Y-m-d');
        $machineTo = $machineFrom;
    }
    $snapshot = $repository->snapshot($machineFrom, $machineTo);
    $attendanceGroups = (new AttendanceProcessor())->process($snapshot, $machineFrom, $machineTo, env('ATTENDANCE_TEST_NIK'));
    if (($_POST['action'] ?? '') === 'prepare_queue') {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            compat_throw(new RuntimeException('Invalid confirmation token.'));
        }
        $added = 0;
        foreach ($attendanceGroups as $group) {
            if ($repository->enqueue($group)) {
                $added++;
            }
        }
        $receiverNotice = $added . ' review entries created. No Talenta request was sent.';
    }
    $queue = $repository->queue();
    $queueCounts = $repository->queueCounts();
    $attendanceStore = new AttendanceEngineRepository($repository->db());
    if ($attendanceStore->installed()) {
        $persistedAttendances = $attendanceStore->recent();
    }
} catch (Throwable $exception) {
    $receiverError = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Local ' . (Database::driver() === 'mysql' ? 'MySQL' : 'PostgreSQL') . ' receiver storage is not ready. Configure .env, inspect the database, then run the local integration migration with PHP 7.4+.';
}
?>
<section class="panel" id="machine-receiver"><div class="section-heading"><h2>Machine receiver</h2><a href="machine-simulator.php" class="btn btn-outline-primary btn-sm">Open machine simulator</a></div><p class="muted">Stateless GET receiver: <code>public/API/index.php</code> · Local storage and Talenta synchronization run independently.</p>
<?php 
if ($receiverError) {
    ?><div class="alert alert-warning"><?php 
    echo e($receiverError);
    ?></div><?php 
}
if ($receiverNotice) {
    ?><div class="alert alert-info"><?php 
    echo e($receiverNotice);
    ?></div><?php 
}
?>
<div class="row g-3 receiver-stats"><?php 
foreach ([['Receiver status', !enabled('MACHINE_RECEIVER_ENABLED') ? 'Disabled' : ($eventCount === null ? 'Enabled · storage unavailable' : 'Enabled · database ready')], ['Last machine request', $auditSummary['last']['at'] ?? 'No requests'], ['Stored events', $eventCount ?? 'Unavailable'], ['Valid requests', $auditSummary['stored'] + $auditSummary['duplicate']], ['Duplicate requests', $auditSummary['duplicate']], ['Rejected / errors', $auditSummary['rejected'] . ' / ' . $auditSummary['error']]] as [$label, $value]) {
    ?><div class="col-md-4 col-xl-2"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong></div><?php 
}
?></div></section>
<section class="panel" id="machine-transactions"><h2>Machine transactions</h2><p class="muted">Latest 100 committed transactions. Direction codes retain their raw device meaning until explicitly configured.</p><div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Time</th><th>Card number</th><th>Terminal</th><th>Direction code</th><th>Status</th></tr></thead><tbody><?php 
foreach ($transactions as $transaction) {
    $when = (new DateTimeImmutable($transaction['event_datetime']))->setTimezone(new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta')));
    ?><tr><td><?php 
    echo e($when->format('Y-m-d'));
    ?></td><td><?php 
    echo e($when->format('H:i:s'));
    ?></td><td><?php 
    echo e($transaction['card_number']);
    ?></td><td><?php 
    echo e($transaction['terminal_id']);
    ?></td><td><?php 
    echo e($transaction['direction_code']);
    ?></td><td>Received</td></tr><?php 
}
if (!$transactions) {
    ?><tr><td colspan="6" class="muted">No stored transactions available.</td></tr><?php 
}
?></tbody></table></div></section>
<section class="panel" id="employee-attendance"><h2>Employee attendance</h2><form method="post" class="row g-3 align-items-end mb-3"><input type="hidden" name="csrf" value="<?php 
echo e($_SESSION['csrf']);
?>"><div class="col-md-3"><label class="form-label" for="machine-from">Shift date from</label><input type="date" class="form-control" name="machine_from" id="machine-from" value="<?php 
echo e($machineFrom);
?>" required></div><div class="col-md-3"><label class="form-label" for="machine-to">Through</label><input type="date" class="form-control" name="machine_to" id="machine-to" value="<?php 
echo e($machineTo);
?>" required></div><div class="col-md-6 d-flex flex-wrap gap-2"><button name="action" value="machine_preview" class="btn btn-outline-primary">Review attendance</button><button name="action" value="prepare_queue" class="btn btn-primary" <?php 
echo $snapshot === null ? 'disabled' : '';
?>>Prepare test employee queue</button></div></form><p class="muted">Configured shifts and terminal directions determine clock-in/out. Unmapped cards, unknown direction, missing taps, repeated taps and ambiguous shifts require review.</p><div class="table-responsive"><table class="table"><thead><tr><th>NIK / employee</th><th>Shift date</th><th>Clock-in</th><th>Clock-out</th><th>Shift</th><th>Validation status</th></tr></thead><tbody><?php 
foreach ($attendanceGroups as $group) {
    ?><tr><td><?php 
    echo e($group['employee_nik'] ?: $group['card_number']);
    ?><br><small><?php 
    echo e($group['employee_name']);
    ?></small></td><td><?php 
    echo e($group['shift_date']);
    ?></td><td><?php 
    echo e($group['clock_in'] ?: 'Missing');
    ?></td><td><?php 
    echo e($group['clock_out'] ?: 'Missing');
    ?></td><td><?php 
    echo e($group['shift']);
    ?></td><td><?php 
    echo e($group['status']);
    foreach ($group['issues'] as $issue) {
        ?><div class="validation"><?php 
        echo e($issue);
        ?></div><?php 
    }
    ?></td></tr><?php 
}
if (!$attendanceGroups) {
    ?><tr><td colspan="6" class="muted">No attendance preview for this date range.</td></tr><?php 
}
?></tbody></table></div></section>
<?php 
require ROOT . '/app/processed-attendance.php';
?>
<section class="panel" id="employee-mapping"><h2>Employee mapping and shift configuration</h2><p class="muted">Explicit mappings are stored only in the integration schema. Configure with <code>bin/console.php employee-map &lt;file.json&gt;</code>, <code>shift</code> and <code>terminal</code>. No identity is inferred from the card.</p><div class="table-responsive"><table class="table"><thead><tr><th>Machine card</th><th>Employee NIK</th><th>Talenta employee ID</th><th>Talenta user ID</th><th>Fingerprint badge</th><th>Shift / verified</th></tr></thead><tbody><?php 
foreach ($snapshot['mappings'] ?? [] as $mapping) {
    ?><tr><?php 
    foreach (['card_number', 'employee_nik', 'talenta_employee_id', 'talenta_user_id', 'badge_number'] as $field) {
        ?><td><?php 
        echo e($mapping[$field] ?? 'Not mapped');
        ?></td><?php 
    }
    ?><td><?php 
    echo e($mapping['shift_code'] ?? 'Not configured');
    ?> / <?php 
    echo in_array($mapping['verified'], [true, 't', 'true', 1, '1'], true) ? 'Verified' : 'Unverified';
    ?></td></tr><?php 
}
?></tbody></table></div></section>
<?php 
require ROOT . '/app/scheduler-status.php';
?>
<section class="panel" id="integration-queue"><h2>Talenta integration queue</h2><div class="row g-3 mb-3"><?php 
foreach ([['Import token', env('MEKARI_IMPORT_TOKEN') !== '' ? 'Configured' : 'Not configured'], ['Pending review', $queueCounts['pending_review'] ?? 0], ['Submitted · reconcile', $queueCounts['submitted_pending_reconciliation'] ?? 0], ['Confirmed success', $queueCounts['confirmed'] ?? 0], ['Failed / uncertain', ($queueCounts['confirmed_failed'] ?? 0) + ($queueCounts['rejected'] ?? 0) + ($queueCounts['unknown'] ?? 0) + ($queueCounts['reserved'] ?? 0)]] as [$label, $value]) {
    ?><div class="col"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong></div><?php 
}
?></div><p class="muted">Only the configured test NIK can enter this queue. CSV preview works without API credentials. Each live attempt requires a fresh review and explicit confirmation.</p><div class="table-responsive"><table class="table"><thead><tr><th>Queue</th><th>NIK</th><th>Shift date</th><th>Synchronization status</th><th>Last API response</th><th>Review</th></tr></thead><tbody><?php 
foreach ($queue as $item) {
    ?><tr><td>#<?php 
    echo e($item['id']);
    ?></td><td><?php 
    echo e($item['employee_nik']);
    ?></td><td><?php 
    echo e($item['shift_date']);
    ?></td><td><?php 
    echo e($item['status']);
    ?></td><td><?php 
    echo e($item['response'] ?? 'Not submitted');
    ?></td><td><a href="queue.php?id=<?php 
    echo e($item['id']);
    ?>">Review CSV</a></td></tr><?php 
}
if (!$queue) {
    ?><tr><td colspan="6" class="muted">No queued synchronization entries.</td></tr><?php 
}
?></tbody></table></div></section>

