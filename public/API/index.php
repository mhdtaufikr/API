<?php

declare (strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
// Stateless machine endpoint. No session, browser authentication or outbound API call.
set_time_limit(12);
$requestId = bin2hex(random_bytes(12));
$audit = new ReceiverAudit();
try {
    ReceiverHttp::validateTransport($_SERVER, $_GET);
    if (PHP_VERSION_ID < 70400) {
        compat_throw(new DomainException('Receiver requires PHP 7.4+. Active runtime: ' . PHP_VERSION . '.', 503));
    }
    $repository = LegacyAttendanceRepository::active() ? new LegacyAttendanceRepository(Database::open('receiver')) : new AttendanceRepository(null, 'receiver');
    $result = (new MachineReceiverService($repository, new MachineRequestParser(), $audit))->receive($_GET, $_SERVER['REMOTE_ADDR'], $requestId);
    if ($result['http'] === 200 && $result['status'] === 'stored' && LegacyAttendanceRepository::active()) {
        HrmsSdfExport::onReceive($requestId, $_SERVER['REMOTE_ADDR']);
    }
    ReceiverHttp::respond($result['http'], $result['status'], $requestId);
} catch (Throwable $error) {
    $http = $error instanceof DomainException ? $error->getCode() : 503;
    $safe = [];
    foreach (['cardno', 'd', 'h', 'm'] as $key) {
        $safe[$key] = is_string($_GET[$key] ?? null) ? substr($_GET[$key], 0, 1024) : '[missing or non-scalar]';
    }
    try {
        $audit->append(['request_id' => $requestId, 'source_ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'php_version' => PHP_VERSION, 'status' => $http < 500 ? 'rejected' : 'error', 'reason' => $error instanceof DomainException ? $error->getMessage() : 'Receiver persistence or audit unavailable', 'payload' => $safe]);
    } catch (Throwable $_ignored) {
        error_log('Machine receiver audit unavailable; request ' . $requestId);
    }
    ReceiverHttp::respond($http, $http < 500 ? 'rejected' : 'error', $requestId);
}
