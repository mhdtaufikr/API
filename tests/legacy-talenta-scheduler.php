<?php

declare(strict_types=1);

putenv('INTEGRATION_STORAGE=legacy_app');
putenv('TALENTA_SCHEDULER_ENABLED=true');
putenv('TALENTA_SCHEDULE_EMPLOYEE_BATCH_SIZE=100');
putenv('TALENTA_SCHEDULE_ROW_BATCH_SIZE=500');
putenv('TALENTA_SCHEDULE_MAX_SUBMISSIONS=4');
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

legacySchedulerCheck(LegacyTalentaScheduledExport::DEFAULT_EMPLOYEE_BATCH_SIZE === 100, 'Default scheduler employee batch covers a normal production workforce');

function fakeScope(int $batch, int $employeeStart, int $employeeCount, int $recordCount): array
{
    $cards = [];
    for ($index = 0; $index < $employeeCount; $index++) {
        $cards[] = 'CARD-' . str_pad((string) ($employeeStart + $index), 4, '0', STR_PAD_LEFT);
    }
    return [
        'id' => 'batch-' . $batch,
        'from' => '2026-10-26',
        'to' => '2026-10-29',
        'cards' => $cards,
        'cursor' => null,
        'row_limit' => 500,
        'record_count' => $recordCount,
    ];
}

function fakePlan(array $scope, array $changes = []): array
{
    $rows = array_fill(0, $scope['record_count'], ['FlFlag' => 0]);
    $events = array_fill(0, $scope['record_count'], ['timestamp' => '2026-10-28T08:00:00+07:00']);
    return array_replace([
        'rows' => $rows,
        'events' => $events,
        'issues' => [],
        'skipped' => [],
        'held' => [],
        'hash' => hash('sha256', $scope['id']),
    ], $changes);
}

// Tests 1 and 2: 250 employees / 1,250 records across deterministic bounded batches.
$pending = [];
for ($batch = 0; $batch < 10; $batch++) {
    $scope = fakeScope($batch + 1, $batch * 25 + 1, 25, 125);
    $pending[$scope['id']] = $scope;
}
$sent = [];
$logs = [];
$scheduler = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope),
    static function (array $scope, string $hash) use (&$pending, &$sent): array {
        legacySchedulerCheck($hash === hash('sha256', $scope['id']), 'Each scheduled batch reuses its reviewed hash');
        $sent[] = $scope['id'];
        unset($pending[$scope['id']]); // Simulates exact FlFlag updates after acceptance.
        return ['status' => 'submitted_pending_reconciliation', 'flag_updated' => true, 'http' => 202, 'id' => 'LEDGER-' . $scope['id']];
    },
    static function (array $summary) use (&$logs): void { $logs[] = $summary; },
    static function (string $from, string $to, int $employeeBatch, int $rowBatch) use (&$pending): iterable {
        legacySchedulerCheck($employeeBatch === 100 && $rowBatch === 500, 'Production-sized employee batches are passed to discovery');
        return array_values($pending);
    }
);

$preview = $scheduler->run(true, new DateTimeImmutable('2026-10-29T13:00:00+07:00'));
legacySchedulerCheck($preview['status'] === 'preview' && $preview['total_unique_employees'] === 250 && $preview['total_pending_records'] === 1250 && $preview['batches_created'] === 10 && $preview['events_pending'] === 1250 && !$sent, 'Preview supports 250 employees and more than 1,000 rows without truncation');

$outside = $scheduler->run(false, new DateTimeImmutable('2026-10-29T13:00:00+07:00'));
legacySchedulerCheck($outside['status'] === 'outside_schedule' && !$sent, 'No-argument scheduled mode still enforces the existing time windows');

$completed = $scheduler->run(false, new DateTimeImmutable('2026-10-29T13:00:00+07:00'), true);
legacySchedulerCheck($completed['status'] === 'partially_completed' && $completed['batches_submitted'] === 4 && $completed['flags_updated'] === 500 && $completed['dispatch_stopped'] === true, 'One run stops at the safe four-import queue limit');
legacySchedulerCheck(count(array_unique($sent)) === 4, 'No batch is submitted twice in one run');

// Test 4: a second run sees no rows whose successful flag updates were confirmed.
$second = $scheduler->run(false, new DateTimeImmutable('2026-10-29T13:01:00+07:00'), true);
legacySchedulerCheck($second['status'] === 'partially_completed' && $second['batches_submitted'] === 4 && count($sent) === 8, 'A subsequent run resumes remaining batches without resubmitting successful records');
$third = $scheduler->run(false, new DateTimeImmutable('2026-10-29T13:02:00+07:00'), true);
legacySchedulerCheck($third['status'] === 'completed' && $third['batches_submitted'] === 2 && count($sent) === 10, 'Remaining batches finish on a later run within the safe queue limit');

$deferredAttempts = [];
$deferred = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope),
    static function (array $scope) use (&$deferredAttempts): array {
        $deferredAttempts[] = $scope['id'];
        return ['status' => 'deferred_retryable', 'flag_updated' => false, 'http' => 400, 'id' => 'BUSY-' . $scope['id']];
    },
    static function (array $summary): void {},
    static fn(): iterable => [fakeScope(1, 1, 1, 1), fakeScope(2, 2, 1, 1)]
);
$deferredResult = $deferred->run(false, new DateTimeImmutable('2026-10-29T13:01:30+07:00'), true);
legacySchedulerCheck($deferredResult['status'] === 'held' && $deferredResult['batches_deferred'] === 1 && $deferredResult['errors'] === 0 && $deferredResult['dispatch_stopped'] === true && $deferredAttempts === ['batch-1'], 'Talenta concurrency response defers one batch and stops later submissions without flag updates');

// Test 3: one rejected API batch does not discard or prevent later independent batches.
$partialScopes = [fakeScope(1, 1, 1, 1), fakeScope(2, 2, 1, 1), fakeScope(3, 3, 1, 1)];
$partialAttempts = [];
$partial = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope),
    static function (array $scope) use (&$partialAttempts): array {
        $partialAttempts[] = $scope['id'];
        if ($scope['id'] === 'batch-2') {
            return ['status' => 'rejected', 'flag_updated' => false, 'http' => 422, 'id' => 'REJECTED'];
        }
        return ['status' => 'submitted_pending_reconciliation', 'flag_updated' => true, 'http' => 202, 'id' => 'OK-' . $scope['id']];
    },
    static function (array $summary): void {},
    static fn(): iterable => $partialScopes
);
$partialResult = $partial->run(false, new DateTimeImmutable('2026-10-29T13:02:00+07:00'), true);
legacySchedulerCheck($partialResult['status'] === 'partially_completed' && $partialResult['batches_submitted'] === 2 && $partialResult['batches_failed'] === 1 && $partialResult['flags_updated'] === 2 && $partialAttempts === ['batch-1', 'batch-2', 'batch-3'], 'A rejected middle batch remains unflagged while later batches continue');

$discoveryFailure = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope),
    static fn(): array => ['status' => 'submitted_pending_reconciliation', 'flag_updated' => true, 'http' => 202, 'id' => 'BEFORE-DISCOVERY-FAILURE'],
    static function (array $summary): void {},
    static function (): iterable {
        yield fakeScope(1, 1, 1, 1);
        throw new RuntimeException('Simulated cursor discovery failure');
    }
);
$discoveryFailureResult = $discoveryFailure->run(false, new DateTimeImmutable('2026-10-29T13:02:30+07:00'), true);
legacySchedulerCheck($discoveryFailureResult['status'] === 'partially_completed' && $discoveryFailureResult['batches_submitted'] === 1 && $discoveryFailureResult['flags_updated'] === 1 && $discoveryFailureResult['batches_failed'] === 1 && $discoveryFailureResult['errors'] === 1, 'A discovery failure after acceptance reports partial completion without erasing successful counts');

// Unknown transport/API outcome is held for reconciliation and never flagged.
$uncertain = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope),
    static fn(): array => ['status' => 'unknown', 'flag_updated' => false, 'http' => 0, 'id' => 'UNKNOWN-LEDGER'],
    static function (array $summary): void {},
    static fn(): iterable => [fakeScope(1, 1, 1, 1)]
);
$uncertainResult = $uncertain->run(false, new DateTimeImmutable('2026-10-29T13:03:00+07:00'), true);
legacySchedulerCheck($uncertainResult['status'] === 'held' && $uncertainResult['batches_held'] === 1 && $uncertainResult['flags_updated'] === 0 && $uncertainResult['errors'] === 1, 'Unknown outcomes are held without flag updates or automatic retry claims');

// Test 5: invalid identity rows are skipped while verified rows continue.
$identityScope = fakeScope(1, 1, 2, 10);
$invalidIdentity = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope, [
        'rows' => array_fill(0, 8, ['FlFlag' => 0]),
        'events' => array_fill(0, 8, ['timestamp' => '2026-10-28T08:00:00+07:00']),
        'skipped' => [['card' => 'INVALID-CARD', 'rows' => 2, 'reason' => 'Unverified identity']],
    ]),
    static fn(): array => ['status' => 'submitted_pending_reconciliation', 'flag_updated' => true, 'http' => 202, 'id' => 'IDENTITY-OK'],
    static function (array $summary): void {},
    static fn(): iterable => [$identityScope]
);
$identityResult = $invalidIdentity->run(false, new DateTimeImmutable('2026-10-29T13:04:00+07:00'), true);
legacySchedulerCheck($identityResult['status'] === 'completed_with_skips' && $identityResult['records_skipped'] === 2 && $identityResult['cards_skipped'] === 1 && $identityResult['flags_updated'] === 8, 'Invalid identities are skipped without blocking verified employees');
legacySchedulerCheck(!str_contains(json_encode($identityResult, JSON_THROW_ON_ERROR), 'INVALID-CARD'), 'Structured scheduler logs contain counts, not employee/card identifiers');

$blockedSenderCalled = false;
$blocked = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope, [
        'rows' => [],
        'events' => [],
        'skipped' => [['card' => 'INVALID-CARD', 'rows' => 3, 'reason' => 'Unverified identity']],
    ]),
    static function () use (&$blockedSenderCalled): array {
        $blockedSenderCalled = true;
        throw new LogicException('Blocked-only input must not send.');
    },
    static function (array $summary): void {},
    static fn(): iterable => [fakeScope(1, 1, 1, 3)]
);
$blockedResult = $blocked->run(false, new DateTimeImmutable('2026-10-29T13:05:00+07:00'), true);
legacySchedulerCheck($blockedResult['status'] === 'held' && $blockedResult['errors'] === 1 && !$blockedSenderCalled, 'A run whose entire input is skipped is not reported as successful');

$attemptedHeld = new LegacyTalentaScheduledExport(
    static fn(array $scope): array => fakePlan($scope, [
        'rows' => [],
        'events' => [],
        'held' => [['rows' => 2, 'reason' => 'Previously attempted event requires reconciliation']],
    ]),
    static function (): array { throw new LogicException('Ledger-held input must not send.'); },
    static function (array $summary): void {},
    static fn(): iterable => [fakeScope(1, 1, 1, 2)]
);
$attemptedHeldResult = $attemptedHeld->run(false, new DateTimeImmutable('2026-10-29T13:06:00+07:00'), true);
legacySchedulerCheck($attemptedHeldResult['status'] === 'held' && $attemptedHeldResult['records_held'] === 2 && $attemptedHeldResult['flags_updated'] === 0, 'Previously attempted events remain held without resend or flag update');

// Held diagnostics expose only a stable category, never the card from an upstream error.
$diagnosticCard = 'SENSITIVE-CARD-123';
$diagnostic = new LegacyTalentaScheduledExport(
    static fn(): array => fakePlan(fakeScope(1, 1, 1, 1), [
        'rows' => [],
        'events' => [],
        'issues' => ['Validasi kartu ' . $diagnosticCard . ' gagal (HTTP 0, cURL 28). Belum ada import dikirim.'],
    ]),
    static function (): array { throw new LogicException('Held preview must not send.'); },
    static function (array $summary): void {},
    static fn(): iterable => [fakeScope(1, 1, 1, 1)]
);
$diagnosticResult = $diagnostic->run(true, new DateTimeImmutable('2026-10-29T13:07:00+07:00'));
legacySchedulerCheck(($diagnosticResult['hold_reasons']['identity_validation_http_0_curl_28'] ?? 0) === 1, 'Held preview reports HTTP and cURL diagnostic categories');
legacySchedulerCheck(!str_contains(json_encode($diagnosticResult, JSON_THROW_ON_ERROR), $diagnosticCard), 'Held diagnostic summary never exposes the card identifier');
legacySchedulerCheck(TalentaEmployeeResolver::retryableCurlError(28) && !TalentaEmployeeResolver::retryableCurlError(60), 'Only transient transport failures are eligible for one identity retry');

// Test 7: interactive/manual preview retains its original employee safety limit.
$interactiveRows = [];
for ($employee = 1; $employee <= 101; $employee++) {
    $interactiveRows[] = ['FcCardNo' => 'CARD-' . $employee];
}
try {
    LegacyManualSend::assertInteractiveEmployeeLimit($interactiveRows);
    throw new LogicException('Interactive limit was removed.');
} catch (RuntimeException $error) {
    legacySchedulerCheck(!($error instanceof LogicException) && str_contains($error->getMessage(), '100 karyawan'), 'Interactive preview still rejects more than 100 employees');
}

// Test 6: the existing BAT/PowerShell/console chain remains intact.
$bat = file_get_contents(ROOT . '/kirimkeTalenta.bat');
$runner = file_get_contents(ROOT . '/bin/run-talenta.ps1');
$console = file_get_contents(ROOT . '/bin/console.php');
legacySchedulerCheck(str_contains($bat, 'bin\run-talenta.ps1') && str_contains($runner, "'talenta:scheduler'") && str_contains($console, "command === 'talenta:scheduler'"), 'Windows Task Scheduler entrypoint remains BAT to PowerShell to console scheduler');
legacySchedulerCheck(str_contains($runner, "@('C:\\xampp\\php\\php.exe', \$fromPath, 'C:\\php\\php.exe')"), 'Talenta launcher prefers the verified XAMPP PHP before an unrelated PATH runtime');

echo $checks . " legacy scheduler batch checks passed. Fake dispatcher only; no database, FlFlag or Talenta request.\n";
