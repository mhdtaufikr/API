<?php

declare (strict_types=1);
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
if (Mekari::submissionStatus(false, 0) !== 'unknown'
    || Mekari::submissionStatus('{"success":false}', 200) !== 'rejected'
    || Mekari::submissionStatus('{"status":"failed"}', 202) !== 'rejected'
    || Mekari::submissionStatus('{"errors":["invalid row"]}', 200) !== 'rejected'
    || Mekari::submissionStatus('{"request_id":"MAG-ok"}', 202) !== 'submitted_pending_reconciliation'
    || Mekari::submissionStatus('{"request_id":"MAG-no"}', 422) !== 'rejected') {
    compat_throw(new RuntimeException('Submission response classification failed.'));
}
$eventKey = str_repeat('a', 64);
$attempted = Mekari::attemptedEventKeySet([
    ['status' => 'submitted_pending_reconciliation', 'event_keys' => [$eventKey, 'INVALID']],
    ['status' => 'unknown', 'event_keys' => [$eventKey]],
]);
if ($attempted !== [$eventKey => true]) {
    compat_throw(new RuntimeException('Durable attempted-event extraction failed.'));
}
echo "PASS: Import references retained without message, credentials, HR fields or HTML.\nNo network or database write.\n";
echo "PASS: Explicit API failure bodies override HTTP 2xx; transport uncertainty remains held.\n";
echo "PASS: Durable ledger keys block accepted and uncertain events across scheduler runs.\n";
