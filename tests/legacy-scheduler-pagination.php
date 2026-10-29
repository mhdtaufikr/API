<?php

declare(strict_types=1);

putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=batchdb');
putenv('INTEGRATION_STORAGE=legacy_app');
require __DIR__ . '/../app/bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP: pdo_sqlite is unavailable; keyset SQL is still covered by lint and the primary runtime test.\n";
    exit(0);
}

$checks = 0;
function paginationCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE ':memory:' AS batchdb");
$pdo->exec('CREATE TABLE batchdb.tbl_rawdata (FdDate TEXT NOT NULL,FcTime TEXT NOT NULL,FcCardNo TEXT NOT NULL,FcDirection TEXT NOT NULL,FcTermNo TEXT NOT NULL,FlFlag INTEGER NOT NULL DEFAULT 0)');
$insert = $pdo->prepare('INSERT INTO batchdb.tbl_rawdata (FdDate,FcTime,FcCardNo,FcDirection,FcTermNo,FlFlag) VALUES (:date,:time,:card,:direction,:terminal,0)');

// 50 employees x 25 immutable tuples = 1,250 pending records.
for ($employee = 1; $employee <= 50; $employee++) {
    for ($event = 0; $event < 25; $event++) {
        $insert->execute([
            'date' => '2026-10-28',
            'time' => sprintf('%02d:%02d:%02d', 6 + intdiv($event, 10), ($employee + $event) % 60, $event % 60),
            'card' => 'CARD-' . str_pad((string) $employee, 4, '0', STR_PAD_LEFT),
            'direction' => '1',
            'terminal' => str_pad((string) (($employee % 5) + 1), 3, '0', STR_PAD_LEFT),
        ]);
    }
}

$repository = new LegacyAttendanceRepository($pdo);
$cards = [];
for ($employee = 1; $employee <= 50; $employee++) {
    $cards[] = 'CARD-' . str_pad((string) $employee, 4, '0', STR_PAD_LEFT);
}
$cursor = null;
$seen = [];
$pages = 0;
$noRepeats = true;
$exactUpdates = true;
$update = $pdo->prepare('UPDATE batchdb.tbl_rawdata SET FlFlag=1 WHERE FdDate=:FdDate AND FcTime=:FcTime AND FcCardNo=:FcCardNo AND FcDirection=:FcDirection AND FcTermNo=:FcTermNo AND FlFlag=0');
do {
    $page = $repository->scheduledRowsPage('2026-10-28', '2026-10-28', $cards, $cursor, 100);
    $pages++;
    foreach ($page['rows'] as $row) {
        $fingerprint = LegacyAttendanceRepository::fingerprint($row);
        if (isset($seen[$fingerprint])) {
            $noRepeats = false;
        }
        $seen[$fingerprint] = true;
        $update->execute(array_intersect_key($row, array_flip(['FdDate', 'FcTime', 'FcCardNo', 'FcDirection', 'FcTermNo'])));
        if ($update->rowCount() !== 1) {
            $exactUpdates = false;
        }
    }
    $cursor = $page['next_cursor'];
} while ($page['has_more']);

paginationCheck($noRepeats && count($seen) === 1250 && $pages === 13, 'More than 1,000 rows are exhausted over stable keyset pages without repeats');
paginationCheck($exactUpdates, 'Each simulated accepted tuple changes exactly one source flag');
paginationCheck((int) $pdo->query('SELECT COUNT(*) FROM batchdb.tbl_rawdata WHERE FlFlag=0')->fetchColumn() === 0, 'Changing flags during paging creates no OFFSET-style gap');

// Add another 200 cards and verify deterministic employee-card keyset discovery.
for ($employee = 51; $employee <= 250; $employee++) {
    $insert->execute([
        'date' => '2026-10-29',
        'time' => '08:00:00',
        'card' => 'CARD-' . str_pad((string) $employee, 4, '0', STR_PAD_LEFT),
        'direction' => '1',
        'terminal' => '001',
    ]);
}
$method = new ReflectionMethod(LegacyAttendanceRepository::class, 'pendingCardPage');
$method->setAccessible(true);
$after = null;
$discovered = [];
do {
    $pageCards = $method->invoke($repository, '2026-10-29', '2026-10-29', $after, 25);
    foreach ($pageCards as $card) {
        $discovered[] = $card;
    }
    $after = $pageCards ? (string) end($pageCards) : null;
} while (count($pageCards) === 25);
paginationCheck(count($discovered) === 200 && count(array_unique($discovered)) === 200, 'Employee discovery pages are deterministic with no duplicate card');

// Duplicate physical tuples remain visible as ambiguity and are never flattened into a sendable row.
$duplicate = ['date' => '2026-10-29', 'time' => '09:00:00', 'card' => 'DUPLICATE', 'direction' => '1', 'terminal' => '001'];
$insert->execute($duplicate);
$insert->execute($duplicate);
$duplicatePage = $repository->scheduledRowsPage('2026-10-29', '2026-10-29', ['DUPLICATE'], null, 10);
paginationCheck(count($duplicatePage['rows']) === 1 && $duplicatePage['rows'][0]['identity_ambiguous'] === true && $duplicatePage['rows'][0]['identical_rows'] === 2 && $duplicatePage['record_count'] === 2, 'Identical source tuples remain held as two ambiguous physical records');

echo $checks . " pagination checks passed against temporary SQLite only. No production DB or Talenta request.\n";
