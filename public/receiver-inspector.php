<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (!enabled('MACHINE_ALLOW_SIMULATOR')) {
    http_response_code(403);
    echo '{"error":"Simulator disabled"}';
    exit;
}
try {
    $requestId = $_GET['request_id'] ?? null;
    if ($requestId !== null) {
        if (!is_string($requestId) || !preg_match('/\A[a-f0-9]{24}\z/', $requestId)) {
            compat_throw(new InvalidArgumentException('Invalid request ID.'));
        }
        $audit = (new ReceiverAudit())->summary($requestId)['entry'];
        if (!$audit) {
            http_response_code(404);
            echo '{"error":"Request not found"}';
            exit;
        }
        $input = $audit['payload'];
    } else {
        $input = $_GET;
        unset($input['parse_only']);
        $audit = null;
    }
    $parsed = (new MachineRequestParser())->parse($input);
    $record = null;
    $status = $requestId === null ? 'Parse only; no database access' : 'Not inserted by this request: ' . ($audit['reason'] ?? $audit['status']);
    if ($requestId !== null && isset($audit['event_hash'])) {
        try {
            $record = (new AttendanceRepository())->findByHash($audit['event_hash']);
            if (in_array($audit['status'], ['stored', 'duplicate'], true)) {
                $status = $record ? $audit['status'] === 'duplicate' ? 'Duplicate; existing database row reused' : 'Stored successfully' : 'Receiver acknowledged, but record lookup returned no row';
            } else {
                $status = 'Receiver did not acknowledge storage: ' . ($audit['reason'] ?? $audit['status']) . ($record ? '. A matching record from another request exists.' : '');
            }
        } catch (Throwable $_ignored) {
            $status = 'Database unavailable';
        }
    }
    echo json_encode(['valid' => true, 'parsed' => $parsed, 'audit' => $audit, 'record' => $record, 'database_status' => $status], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['valid' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $_ignored) {
    http_response_code(503);
    echo '{"error":"Inspection unavailable"}';
}
