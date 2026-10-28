<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$runtimeContract = ROOT . '/config/contract.json';
$runtimeEvidence = ROOT . '/storage/postman.json';
$packaged = jsonFile(ROOT . '/config/talenta-attendance-contract.json');
$fingerprint = hash('sha256', json_encode($packaged, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

if ($fingerprint !== '3a547c6fb4acf32decc07032ace6d999db379b95f0bd40f9cfd981ccb6677239') {
    throw new RuntimeException('FAIL: Packaged contract fingerprint changed.');
}
if (($packaged['method'] ?? '') !== 'POST'
    || ($packaged['path'] ?? '') !== Mekari::PATH
    || ($packaged['mode'] ?? '') !== 'formdata'
    || array_keys($packaged['fields'] ?? []) !== ['user_id', 'token', 'file']
    || array_keys($packaged['csv_columns'] ?? []) !== ['Date', 'Time', 'Employee_ID', 'Direction', 'Terminal_Number', 'Flag']) {
    throw new RuntimeException('FAIL: Packaged contract does not match the reviewed Attendance MKM profile.');
}
if (is_file($runtimeContract) xor is_file($runtimeEvidence)) {
    throw new RuntimeException('FAIL: Local runtime contract evidence is only partially present.');
}

$loader = new ReflectionMethod(Mekari::class, 'loadContract');
$loader->setAccessible(true);
$fallback = $loader->invoke(null, ROOT . '/storage/missing-contract.json', ROOT . '/storage/missing-postman.json', ROOT . '/config/talenta-attendance-contract.json');
if (($fallback['attendance_machine_profile']['name'] ?? '') !== 'Attendance MKM') {
    throw new RuntimeException('FAIL: Clean deployment did not load the packaged contract.');
}

echo "PASS: Packaged Attendance MKM contract is complete, credential-free and fingerprinted.\n";
echo "PASS: Clean deployment without ignored runtime files loads the packaged contract.\n";
echo "No database mutation or Talenta request was made.\n";
