<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
try {
    $pdo=new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT').';charset=utf8mb4',env('DB_USERNAME'),env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    echo 'Server: '.$pdo->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
    $stmt=$pdo->prepare("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA NOT IN ('mysql','information_schema','performance_schema','phpmyadmin','sys') AND (TABLE_SCHEMA LIKE :schema OR TABLE_NAME LIKE :table OR TABLE_NAME = :raw) ORDER BY TABLE_SCHEMA,TABLE_NAME,ORDINAL_POSITION");
    $stmt->execute(['schema'=>'%attend%','table'=>'%attend%','raw'=>'tbl_rawdata']);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);saveJson(ROOT.'/storage/mysql-schema-before-migration.json',$rows);
    echo json_encode($rows,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
    $stmt=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=:name');$stmt->execute(['name'=>'mkm_attendance_local']);
    echo 'Dedicated local database: '.($stmt->fetchColumn()===false?'not present':'already exists').PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR,'Local MySQL inspection failed ('.get_class($error).'). No schema changed.'.PHP_EOL);exit(1); }
