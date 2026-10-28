<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$count = 0;
function scheduleCheck(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException('FAIL: ' . $label));
    }
    $count++;
    echo 'PASS: ' . $label . PHP_EOL;
}
foreach (['08:00', '12:00', '18:00'] as $time) {
    scheduleCheck(TalentaScheduledExport::slot(new DateTimeImmutable('2026-10-27T' . $time . ':00+07:00')) === '2026-10-27@' . $time, 'Due at ' . $time . ' WIB');
}
foreach (['07:59', '08:15', '11:59', '12:15', '17:59', '18:15', '23:00'] as $time) {
    scheduleCheck(TalentaScheduledExport::slot(new DateTimeImmutable('2026-10-27T' . $time . ':00+07:00')) === null, 'No scheduled send at ' . $time);
}
scheduleCheck(TalentaScheduledExport::slot(new DateTimeImmutable('2026-10-27T01:00:00Z')) === '2026-10-27@08:00', 'Schedule converts UTC to WIB');
$record = ['review_reasons' => ['Shift window is not closed; attendance is provisional'], 'actual_clock_in' => '2026-10-27T07:30:00+07:00', 'actual_clock_out' => null, 'events' => [['id' => 1, 'direction' => 'IN', 'direction_reliable' => true]], 'source_classifications' => [1 => 'clock_in']];
scheduleCheck(count(TalentaScheduledExport::eligibleEvents($record)) === 1, 'Reliable morning IN can be sent before clock-out exists');
$bad = $record;
$bad['events'][0]['direction_reliable'] = false;
scheduleCheck(TalentaScheduledExport::eligibleEvents($bad) === [], 'Unreliable partial IN is held');
$bad = $record;
$bad['review_reasons'][] = 'Ambiguous source log';
scheduleCheck(TalentaScheduledExport::eligibleEvents($bad) === [], 'Ambiguous events stay held');
$bad = $record;
$bad['actual_clock_in'] = null;
scheduleCheck(TalentaScheduledExport::eligibleEvents($bad) === [], 'An isolated OUT does not bypass missing IN review');
$record['actual_clock_out'] = '2026-10-27T16:30:00+07:00';
$record['events'][] = ['id' => 2, 'direction' => 'OUT', 'direction_reliable' => true];
$record['events'][] = ['id' => 3, 'direction' => 'IN', 'direction_reliable' => true];
$record['source_classifications'] += [2 => 'clock_out', 3 => 'excluded_duplicate'];
scheduleCheck(array_column(TalentaScheduledExport::eligibleEvents($record), 'id') === [1, 2], 'Selected actual IN/OUT exported; duplicate taps excluded');
$one = ['timestamp' => '2026-10-27T07:30:00+07:00'];
$same = ['timestamp' => '2026-10-27T00:30:00Z'];
scheduleCheck(Mekari::eventKey($one) === Mekari::eventKey($same), 'Duplicate submission key preserves the actual event instant');
if (!enabled('TALENTA_SCHEDULER_ENABLED')) {
    scheduleCheck((new TalentaScheduledExport())->run(false, new DateTimeImmutable('2026-10-27T08:00:00+07:00'))['status'] === 'disabled', 'Scheduler remains disabled even inside a scheduled slot');
}
$key = random_bytes(32);
$profile = ['fingerprint' => hash('sha256', 'test-profile'), 'company_id' => 'TEST', 'test_nik' => 'TEST-NIK', 'times' => TalentaScheduledExport::TIMES];
$profile['signature'] = hash_hmac('sha256', json_encode($profile, JSON_THROW_ON_ERROR), $key);
TalentaScheduledExport::validateApproval($profile, $profile['fingerprint'], $key);
scheduleCheck(true, 'Approved profile signature is verified');
$changed = $profile;
$changed['test_nik'] = 'ANOTHER-NIK';
foreach ([[$changed, $profile['fingerprint'], $key, 'Tampered approval is rejected'], [$profile, hash('sha256', 'changed-profile'), $key, 'Changed configuration invalidates approval'], [$profile, $profile['fingerprint'], random_bytes(32), 'Wrong signing key is rejected'], [[], $profile['fingerprint'], $key, 'Missing approval is rejected']] as [$candidate, $fingerprint, $secret, $label]) {
    try {
        TalentaScheduledExport::validateApproval($candidate, $fingerprint, $secret);
        compat_throw(new LogicException('Unexpected valid approval'));
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) {
            compat_throw($error);
        }
        scheduleCheck(true, $label);
    }
}
echo $count . " scheduler checks passed. No API submission, task installation or activation.\n";
