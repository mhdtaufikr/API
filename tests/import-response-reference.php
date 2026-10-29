<?php

declare (strict_types=1);
putenv('APP_KEY=base64:' . base64_encode(str_repeat('k', 32)));
require __DIR__ . '/../app/bootstrap.php';
$body = json_encode(['request_id' => 'MAG-test-123', 'token' => 'NEVER-EXPOSE', 'message' => 'NEVER-EXPOSE', 'data' => ['job_id' => 451, 'employee_name' => 'NEVER-EXPOSE']], JSON_THROW_ON_ERROR);
$safe = Mekari::responseReference($body);
if ($safe !== ['request_id' => 'MAG-test-123', 'job_id' => '451']) {
    compat_throw(new RuntimeException('Reference whitelist failed.'));
}
if (Mekari::responseReference('<html>not-json</html>') !== []) {
    compat_throw(new RuntimeException('Non JSON response incorrectly decoded.'));
}
if (Mekari::responseReference('{"task_id":{"secret":"x"},"import_id":"<script>bad</script>"}') !== []) {
    compat_throw(new RuntimeException('Invalid reference leaked.'));
}
$busyBody = json_encode(['status' => 'error', 'message' => 'input validation error', 'errors' => ['there is another export or import process going on, please retry later']], JSON_THROW_ON_ERROR);
if (Mekari::submissionStatus(false, 0) !== 'unknown'
    || Mekari::submissionStatus('{"success":false}', 200) !== 'rejected'
    || Mekari::submissionStatus('{"status":"failed"}', 202) !== 'rejected'
    || Mekari::submissionStatus('{"errors":["invalid row"]}', 200) !== 'rejected'
    || Mekari::submissionStatus('{"request_id":"MAG-ok"}', 202) !== 'submitted_pending_reconciliation'
    || Mekari::submissionStatus('{"request_id":"MAG-no"}', 422) !== 'rejected'
    || Mekari::submissionStatus($busyBody, 400) !== 'deferred_retryable'
    || Mekari::submissionStatus($busyBody, 422) !== 'rejected') {
    compat_throw(new RuntimeException('Submission response classification failed.'));
}
$eventKey = str_repeat('a', 64);
$rejectedKey = str_repeat('d', 64);
$attempted = Mekari::attemptedEventKeySet([
    ['status' => 'submitted_pending_reconciliation', 'event_keys' => [$eventKey, 'INVALID']],
    ['status' => 'unknown', 'event_keys' => [$eventKey]],
    ['status' => 'deferred_retryable', 'event_keys' => [str_repeat('b', 64)]],
    ['status' => 'rejected', 'http' => 400, 'event_keys' => [$rejectedKey]],
]);
if ($attempted !== [$eventKey => true, $rejectedKey => true]) {
    compat_throw(new RuntimeException('Durable attempted-event extraction failed.'));
}
$historicalKey = str_repeat('c', 64);
$encryptedBusyBody = (new CredentialCipher(env('APP_KEY')))->encrypt($busyBody, 'MEKARI_IMPORT_RESPONSE');
if (Mekari::attemptedEventKeySet([['status' => 'rejected', 'http' => 400, 'response_encrypted' => $encryptedBusyBody, 'event_keys' => [$historicalKey]]]) !== []) {
    compat_throw(new RuntimeException('Historical Talenta concurrency rejection did not become safely retryable.'));
}
echo "PASS: Import references retained without message, credentials, HR fields or HTML.\nNo network or database write.\n";
echo "PASS: Explicit API failure bodies override HTTP 2xx; transport uncertainty remains held.\n";
echo "PASS: Durable ledger keys block accepted and uncertain events across scheduler runs.\n";
echo "PASS: Exact Talenta concurrency rejection is deferred and safely retryable; other rejections remain blocked.\n";
