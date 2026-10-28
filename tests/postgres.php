<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (Database::driver() !== 'pgsql') {
    echo "SKIP: Current local driver is not PostgreSQL; run tests/database.php.\n";
    exit;
}
if (env('DB_DATABASE') === '') {
    echo "SKIP: PostgreSQL integration checks need the existing local DB_DATABASE and credentials in .env.\n";
    exit(0);
}
if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing integration tests against a non-local database.\n");
    exit(1);
}
$pdo = null;
try {
    $pdo = Database::open();
    $repository = new AttendanceRepository($pdo);
    $repository->assertInstalled();
    $pdo->beginTransaction();
    $card = (string) random_int(8000, 9999);
    $date = (new DateTimeImmutable('yesterday'))->setTime(7, 25, random_int(0, 59));
    $input = ['cardno' => '9980002T' . $card . $date->format('YmdHis') . '10', 'd' => $date->format('Y/m/d'), 'h' => $date->format('H:i'), 'm' => '998'];
    $event = (new MachineRequestParser())->parse($input);
    if ($repository->findByHash($event['event_hash'])) {
        compat_throw(new RuntimeException('Random test event collides; run again.'));
    }
    $first = $repository->store($event, $input, '127.0.0.1');
    $second = $repository->store($event, $input, '127.0.0.1');
    if ($first['duplicate'] || !$second['duplicate'] || $first['id'] !== $second['id']) {
        compat_throw(new RuntimeException('PostgreSQL duplicate protection failed.'));
    }
    $stored = $repository->findByHash($event['event_hash']);
    if ($stored['raw_message'] !== $input['cardno'] || json_decode($stored['request_payload'], true) !== $input) {
        compat_throw(new RuntimeException('Raw payload round-trip failed.'));
    }
    $input['cardno'] = '9980002T' . $card . $date->setTime(16, 28)->format('YmdHis') . '20';
    $input['h'] = '16:28';
    $secondEvent = (new MachineRequestParser())->parse($input);
    $repository->store($secondEvent, $input, '127.0.0.1');
    $rows = $repository->snapshot($date->format('Y-m-d'), $date->format('Y-m-d'))['rows'];
    if (count(array_filter($rows, fn($r) => $r['card_number'] === $card && $r['terminal_id'] === '998')) !== 2) {
        compat_throw(new RuntimeException('Two-event persistence failed.'));
    }
    $pdo->rollBack();
    echo "PASS: PostgreSQL prepared insert, raw payload round-trip, unique duplicate detection and two-event reads. Test transaction rolled back; no Talenta request.\n";
} catch (Throwable $error) {
    if (($pdo !== null ? $pdo->inTransaction() : null)) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . ($error instanceof PDOException ? 'PostgreSQL connection/schema/query failed.' : $error->getMessage()) . PHP_EOL);
    exit(1);
}
