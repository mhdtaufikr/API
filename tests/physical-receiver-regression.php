<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'parent';

if ($mode !== 'parent') {
    $values = [
        'DB_DATABASE' => 'mkm_ssmdb',
        'INTEGRATION_STORAGE' => 'legacy_app',
        'LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED' => 'true',
        'MACHINE_RECEIVER_ENABLED' => 'true',
        'MACHINE_ALLOWED_IPS' => '*',
        'LEGACY_DIRECTION_MAP' => '{"10":"1"}',
    ];
    if ($mode === 'disabled') {
        $values['MACHINE_RECEIVER_ENABLED'] = 'false';
    } elseif ($mode === 'missing-physical-confirmation') {
        $values['LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED'] = 'false';
    } elseif ($mode === 'missing-direction-map') {
        $values['LEGACY_DIRECTION_MAP'] = '{}';
    }
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
    }
    require __DIR__ . '/../app/bootstrap.php';
    if ($mode === 'response-success') {
        ReceiverHttp::respond(200, 'stored', str_repeat('a', 24));
    }
    if ($mode === 'response-duplicate') {
        ReceiverHttp::respond(200, 'duplicate', str_repeat('b', 24));
    }
    if ($mode === 'response-failure') {
        ReceiverHttp::respond(503, 'error', str_repeat('c', 24));
    }
    $event = ['direction_code' => '10'];
    if ($mode === 'disabled') {
        try {
            ReceiverHttp::validateTransport(
                ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '172.17.211.245', 'QUERY_STRING' => ''],
                []
            );
        } catch (DomainException $error) {
            if ($error->getCode() === 503) {
                echo "PASS: Disabled receiver returns HTTP 503 before persistence.\n";
                exit(0);
            }
        }
        throw new RuntimeException('Disabled receiver was not rejected with 503.');
    }
    $allowed = LegacyAttendanceRepository::allowsProductionPhysicalReceiver(
        $event,
        '172.17.211.245',
        null
    );
    $expected = $mode === 'enabled';
    if ($allowed !== $expected) {
        throw new RuntimeException('Unexpected physical receiver gate result for ' . $mode . '.');
    }
    echo 'PASS: Physical receiver gate mode ' . $mode . ' returned ' . ($allowed ? 'allowed' : 'blocked') . ".\n";
    exit(0);
}

require __DIR__ . '/../app/bootstrap.php';

foreach (['enabled', 'disabled', 'missing-physical-confirmation', 'missing-direction-map', 'response-success', 'response-duplicate', 'response-failure'] as $childMode) {
    $process = proc_open(
        [PHP_BINARY, __FILE__, $childMode],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        ROOT
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start physical receiver regression child: ' . $childMode);
    }
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, $errors);
        throw new RuntimeException('Physical receiver regression child failed: ' . $childMode);
    }
    if (str_starts_with($childMode, 'response-')) {
        $expected = $childMode === 'response-failure' ? 'ERROR' : 'OK';
        if ($output !== $expected) {
            throw new RuntimeException('Unexpected exact response body for ' . $childMode . '.');
        }
        echo 'PASS: Exact ' . $childMode . ' body is ' . $expected . " with no trailing newline.\n";
    } else {
        echo $output;
    }
}

$path = ROOT . '/storage/test-response-contract-' . bin2hex(random_bytes(6)) . '.json';
try {
    file_put_contents($path, '{"success":"OK"}', LOCK_EX);
    try {
        ReceiverHttp::responseContract($path);
        throw new LogicException('Invalid response contract was accepted.');
    } catch (RuntimeException $error) {
        if ($error instanceof LogicException) {
            throw $error;
        }
        echo "PASS: Invalid acknowledgment contract is rejected before receiver persistence.\n";
    }
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

echo "Physical receiver configuration regression checks passed. No database or Talenta request was made.\n";
