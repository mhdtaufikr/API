<?php

declare (strict_types=1);
putenv('ALLOW_PRODUCTION_WRITE=true');
putenv('DRY_RUN=false');
require __DIR__ . '/../app/bootstrap.php';
if (Database::driver() !== 'mysql' || env('DB_DATABASE') !== 'mkm_attendance_local' || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || env('INTEGRATION_STORAGE') !== 'legacy_app') {
    compat_throw(new RuntimeException('Local fixture database only.'));
}
$pdo = Database::open();
$before = $pdo->query('SELECT * FROM tbl_rawdata')->fetchAll(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
try {
    $row = ['FdDate' => '2001-01-01', 'FcTime' => '01:02:03', 'FcCardNo' => 'FIXTURE-' . bin2hex(random_bytes(6)), 'FcDirection' => '1', 'FcTermNo' => '999', 'FlFlag' => 0];
    $insert = $pdo->prepare('INSERT INTO tbl_rawdata (FdDate,FcTime,FcCardNo,FcDirection,FcTermNo,FlFlag) VALUES (:FdDate,:FcTime,:FcCardNo,:FcDirection,:FcTermNo,:FlFlag)');
    $insert->execute($row);
    $repo = new LegacyAttendanceRepository($pdo);
    $repo->markAccepted([$row]);
    $get = $pdo->prepare('SELECT FlFlag FROM tbl_rawdata WHERE FcCardNo=:card');
    $get->execute(['card' => $row['FcCardNo']]);
    if ((int) $get->fetchColumn() !== 1) {
        compat_throw(new RuntimeException('Flag did not update'));
    }
    echo "PASS: Exact selected fixture changes from 0 to 1.\n";
    try {
        $repo->markAccepted([$row]);
        compat_throw(new LogicException('Repeat flag update accepted'));
    } catch (RuntimeException $e) {
        if ($e instanceof LogicException) {
            compat_throw($e);
        }
        echo "PASS: Already marked source is refused.\n";
    }
    $row['FcCardNo'] = 'DUPLICATE-' . bin2hex(random_bytes(6));
    $insert->execute($row);
    $insert->execute($row);
    try {
        $repo->markAccepted([$row]);
        compat_throw(new LogicException('Ambiguous source accepted'));
    } catch (RuntimeException $e) {
        if ($e instanceof LogicException) {
            compat_throw($e);
        }
        echo "PASS: Identical rows are not bulk-marked.\n";
    }
} finally {
    $pdo->rollBack();
}
if ($pdo->query('SELECT * FROM tbl_rawdata')->fetchAll(PDO::FETCH_ASSOC) !== $before) {
    compat_throw(new RuntimeException('Original source changed'));
}
echo "PASS: All fixture flag changes rolled back; original rows and flags unchanged. No API request.\n";
