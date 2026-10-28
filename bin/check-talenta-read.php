<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try {
    $result=(new TalentaAttendanceReader())->fetch(['from'=>date('Y-m-d'),'to'=>date('Y-m-d'),'page'=>1,'limit'=>10]);
    echo json_encode(['http'=>$result['http'],'rows'=>count($result['rows']),'pagination'=>$result['pagination'],'fetched_at'=>$result['fetched_at']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $error) { fwrite(STDERR,$error instanceof PDOException?"Credential database unavailable.\n":$error->getMessage().PHP_EOL);exit(1); }
