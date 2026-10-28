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
echo "PASS: Import references retained without message, credentials, HR fields or HTML.\nNo network or database write.\n";
