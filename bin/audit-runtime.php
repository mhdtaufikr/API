<?php
require __DIR__.'/../app/bootstrap.php';
echo 'driver='.Database::driver().PHP_EOL.'database='.env('DB_DATABASE').PHP_EOL.'pdo='.implode(',',PDO::getAvailableDrivers()).PHP_EOL;
try {$pdo=Database::connect();$tables=[];foreach(Database::columns($pdo) as $c)$tables[$c['table_schema'].'.'.$c['table_name']]=true;echo implode(PHP_EOL,array_keys($tables)).PHP_EOL;$pdo->rollBack();}catch(Throwable $e){echo 'Connection unavailable: '.get_class($e).PHP_EOL;exit(1);}