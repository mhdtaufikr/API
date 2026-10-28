<?php

declare (strict_types=1);
putenv('DRY_RUN=true');
// Offline tests never inherit enabled local manual-write settings.
require __DIR__ . '/../app/bootstrap.php';
$contract = LegacyTrialSetup::draftContract();
if (array_keys($contract['csv_columns']) !== ['Date', 'Time', 'Employee_ID', 'Direction', 'Terminal_Number', 'Flag']) {
    compat_throw(new RuntimeException('Verified Attendance MKM profile differs.'));
}
if ($contract['attendance_machine_profile']['date_column'] !== 1 || $contract['attendance_machine_profile']['time_column'] !== 2 || $contract['attendance_machine_profile']['barcode_column'] !== 3) {
    compat_throw(new RuntimeException('Wrong token profile positions.'));
}
echo "PASS: Attendance MKM uses date 1, time 2, barcode 3, not CSV API layout.\n";
$event = ['nik' => '2120', 'badge_number' => '2120', 'timestamp' => '2026-10-27T07:20:36+07:00', 'direction' => 'UNKNOWN', 'raw_direction' => '1', 'terminal_id' => '004', 'source_flag' => 0];
$csv = Mekari::importCsv([$event], $contract);
if (!str_contains($csv, '2026-10-27,07:20:36,2120,1,004,0')) {
    compat_throw(new RuntimeException('Timestamp/column value mapping differs.'));
}
echo "PASS: YYYY-MM-DD date and seconds preserved in separate columns; raw code not inferred IN/OUT.\n";
$contract['csv_columns']['Time']['format'] = 'H:i';
try {
    Mekari::importCsv([$event], $contract);
    compat_throw(new LogicException('Seconds silently dropped'));
} catch (RuntimeException $e) {
    if ($e instanceof LogicException) {
        compat_throw($e);
    }
    echo "PASS: Minute-only profile cannot silently lose seconds.\n";
}
try {
    LegacyTrialSetup::activate('Y-m-d', 'H:i:s', 'wrong', false);
    compat_throw(new LogicException('Activation accepted'));
} catch (RuntimeException $e) {
    if ($e instanceof LogicException) {
        compat_throw($e);
    }
    echo "PASS: Activation requires explicit typed/profile approval.\n";
}
if (Mekari::ready(true)) {
    compat_throw(new RuntimeException('Live trial unexpectedly active.'));
}
echo "PASS: Live trial remains disabled until operator activation. No network request or config write.\n";
