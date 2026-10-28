<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if(Database::driver()!=='pgsql'||!in_array(env('APP_ENV'),['testing','staging'],true)){echo "SKIP: PostgreSQL staging configuration required; no production mutations.\n";exit;}
$pdo=Database::open('reader');$service=new IntegrationSettings($pdo,env('APP_KEY'));$row=$service->row();
if(!$row)throw new RuntimeException('Provision config:install/config:seed on staging first.');
$service->values();$history=json_decode($row['audit_history'],true,512,JSON_THROW_ON_ERROR);
if(!is_array($history))throw new RuntimeException('Invalid JSONB audit history.');
echo "PASS: PostgreSQL connection, tbl_config query/decryption and JSONB audit read.\n";
echo "Read-only check only. Staging install/seed/save/repeat-save still need deployment validation; no API request.\n";
