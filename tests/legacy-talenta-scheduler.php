<?php

declare(strict_types=1);

putenv('INTEGRATION_STORAGE=legacy_app');
putenv('TALENTA_SCHEDULER_ENABLED=true');
require __DIR__ . '/../app/bootstrap.php';

$checks = 0;
function legacySchedulerCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}

$rows = [
    ['FdDate' => '2026-10-28', 'FcTime' => '07:30:00', 'FcCardNo' => '2120', 'FcDirection' => '1', 'FcTermNo' => '004', 'FlFlag' => 0],
];
$events = [
    ['nik' => '2120', 'timestamp' => '2026-10-28T07:30:00+07:00'],
];
$plan = ['rows' => $rows, 'events' => $events, 'issues' => [], 'hash' => hash('sha256', 'plan')];
$sent = 0;
$logs = [];
$previewedDates = [];
$scheduler = new LegacyTalentaScheduledExport(
    static function (array $scope) use ($plan, &$previewedDates): array {
        $previewedDates[] = $scope[0];
        if ($scope[0] !== '2026-10-28') {
            throw new NoPendingLegacyAttendanceException('none');
        }
        return $plan;
    },
    static function (array $scope, string $hash) use (&$sent, $plan): array {
        $sent++;
        legacySchedulerCheck($hash === $plan['hash'], 'Scheduled send reuses the reviewed preview hash');
        return ['status' => 'submitted_pending_reconciliation', 'flag_updated' => true, 'http' => 202, 'id' => 'TEST-LEDGER'];
    },
    static function (array $summary) use (&$logs): void {
        $logs[] = $summary;
    }
);

$preview = $scheduler->run(true, new DateTimeImmutable('2026-10-28T12:27:00+07:00'));
legacySchedulerCheck($preview['status'] === 'preview' && $preview['events_pending'] === 1 && $sent === 0, 'Preview works outside a slot without sending or updating flags');
legacySchedulerCheck($previewedDates === ['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28'], 'Lookback is partitioned into bounded daily batches');

$previewedDates = [];
$outside = $scheduler->run(false, new DateTimeImmutable('2026-10-28T12:27:00+07:00'));
legacySchedulerCheck($outside['status'] === 'outside_schedule' && $sent === 0, 'Live scheduler refuses an out-of-window manual run');

$previewedDates = [];
$completed = $scheduler->run(false, new DateTimeImmutable('2026-10-28T18:00:00+07:00'));
legacySchedulerCheck($completed['status'] === 'completed' && $completed['batches_submitted'] === 1 && $completed['flags_updated'] === 1 && $sent === 1, 'Scheduled acceptance updates the exact legacy flag batch');
legacySchedulerCheck(count($logs) === 2 && $logs[1]['ledger_ids'] === ['TEST-LEDGER'], 'Non-preview scheduler outcomes are audited without raw attendance payloads');

$held = new LegacyTalentaScheduledExport(
    static function (array $scope) use ($rows, $events): array {
        if ($scope[0] !== '2026-10-28') {
            throw new NoPendingLegacyAttendanceException('none');
        }
        return ['rows' => $rows, 'events' => $events, 'issues' => ['Identity held'], 'hash' => 'held'];
    },
    static function (): array {
        throw new LogicException('Held batch must not send.');
    },
    static function (array $summary): void {
    }
);
$heldResult = $held->run(false, new DateTimeImmutable('2026-10-28T18:00:00+07:00'));
legacySchedulerCheck($heldResult['status'] === 'held' && $heldResult['errors'] === 1, 'Validation issues hold the whole scheduled batch before submission');

$empty = new LegacyTalentaScheduledExport(
    static function (): array {
        throw new NoPendingLegacyAttendanceException('none');
    },
    static function (): array {
        throw new LogicException('Empty batch must not send.');
    },
    static function (array $summary): void {
    }
);
$emptyResult = $empty->run(false, new DateTimeImmutable('2026-10-28T18:00:00+07:00'));
legacySchedulerCheck($emptyResult['status'] === 'no_pending' && $emptyResult['errors'] === 0, 'No pending legacy rows is a successful no-op');

echo $checks . " legacy scheduler checks passed. Fake dispatcher only; no database, FlFlag or Talenta request.\n";
