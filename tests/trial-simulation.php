<?php

declare (strict_types=1);
putenv('DB_DATABASE=mkm_ssmdb');
putenv('INTEGRATION_STORAGE=legacy_app');
putenv('MACHINE_ALLOW_SIMULATOR=true');
putenv('MACHINE_RECEIVER_ENABLED=true');
putenv('LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED=true');
putenv('LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED=true');
putenv('MACHINE_ALLOWED_IPS=172.17.211.245');
putenv('LEGACY_DIRECTION_MAP={"10":"1"}');
require __DIR__ . '/../app/bootstrap.php';
$input = ['cardno' => '0040002T21202026102509403610', 'd' => '2026/10/25', 'h' => '09:40', 'm' => '004'];
$event = TrialSimulation::parse($input);
if ($event['card_number'] !== '2120' || $event['event_datetime'] !== '2026-10-25T09:40:36+07:00') {
    compat_throw(new RuntimeException('Trial timestamp/card changed.'));
}
echo "PASS: Trial preserves card 2120 and original timestamp precision.\n";
if (!LegacyAttendanceRepository::allowsProductionSimulatorTrial($event, '127.0.0.1', '1')) {
    compat_throw(new RuntimeException('Confirmed localhost production simulator trial rejected.'));
}
echo "PASS: Confirmed production exception accepts only the localhost simulator contract.\n";
foreach ([
    [$event, '10.0.0.5', '1'],
    [$event, '127.0.0.1', null],
    [array_merge($event, ['card_number' => '2121']), '127.0.0.1', '1'],
] as $case) {
    if (LegacyAttendanceRepository::allowsProductionSimulatorTrial($case[0], $case[1], $case[2])) {
        compat_throw(new RuntimeException('Production simulator exception became too broad.'));
    }
}
echo "PASS: Remote, physical-receiver, and non-2120 production writes remain blocked.\n";
if (!LegacyAttendanceRepository::allowsProductionPhysicalReceiver($event, '172.17.211.245', null)) {
    compat_throw(new RuntimeException('Exact-IP confirmed physical receiver rejected.'));
}
if (LegacyAttendanceRepository::allowsProductionPhysicalReceiver($event, '172.17.211.246', null)) {
    compat_throw(new RuntimeException('Unlisted production physical receiver accepted.'));
}
echo "PASS: Production physical receiver requires its exact configured source IP and direction map.\n";
foreach (['2121', '0000', '9999'] as $card) {
    $other = $input;
    $other['cardno'] = str_replace('T2120', 'T' . $card, $input['cardno']);
    try {
        TrialSimulation::parse($other);
        compat_throw(new LogicException('Non-trial card accepted.'));
    } catch (InvalidArgumentException $_ignored) {
        echo "PASS: Non-trial card rejected before database access.\n";
    }
}
$broken = $input;
$broken['h'] = '07:20';
try {
    TrialSimulation::parse($broken);
    compat_throw(new LogicException('Mismatched raw timestamp accepted.'));
} catch (InvalidArgumentException $_ignored) {
    echo "PASS: Forged/mismatched raw timestamp rejected.\n";
}
echo "No database or Talenta request.\n";
