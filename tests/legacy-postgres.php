<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(!extension_loaded('pdo_pgsql')){echo "SKIP: pdo_pgsql is not installed; no PostgreSQL compatibility claim.\n";exit;}
if(Database::driver()!=='pgsql'||env('INTEGRATION_STORAGE')!=='legacy_local'||!in_array(env('DB_HOST'),['127.0.0.1','localhost','::1'],true)||env('DB_DATABASE')!=='mkm_attendance_local'){echo "SKIP: Configure an isolated local PostgreSQL legacy copy first.\n";exit;}
$pdo=Database::open('reader');$repo=new LegacyAttendanceRepository($pdo);$repo->inspect();
echo 'PASS: Connected to local PostgreSQL and inspected exact mixed-case legacy columns.'.PHP_EOL;
$repo->rows('2026-10-25','2026-10-27','2120');echo 'PASS: PostgreSQL raw retrieval executed read-only.'.PHP_EOL;
