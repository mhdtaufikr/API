<?php
declare(strict_types=1);
putenv('DRY_RUN=true');require __DIR__.'/../app/bootstrap.php';
$body=['data'=>['employment'=>['employee_id'=>'2038','id'=>123456,'barcode'=>'2038','company_id'=>env('MEKARI_COMPANY_ID')]]];
$map=TalentaEmployeeResolver::normalize($body,'2038');if($map['badge_number']!=='2038'||$map['talenta_user_id']!=='123456')throw new RuntimeException('Mapping failed');echo "PASS: Other employee mapping preserves company/user/barcode identity.\n";
$body['data']['employment']['barcode']='OTHER';try{TalentaEmployeeResolver::normalize($body,'2038');throw new LogicException('Mismatch accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;echo "PASS: Barcode mismatch is held rather than guessed.\n";}
$body['data']['employment']['barcode']='';try{TalentaEmployeeResolver::normalize($body,'2038');throw new LogicException('Empty barcode accepted');}catch(UnsendableTalentaIdentityException $e){echo "PASS: Empty barcode is classified as a safely skippable identity.\n";}
$body['data']['employment']['barcode']='2038';$body['data']['employment']['company_id']='OTHER';try{TalentaEmployeeResolver::normalize($body,'2038');throw new LogicException('Company mismatch accepted');}catch(RuntimeException $e){if($e instanceof LogicException)throw $e;echo "PASS: Wrong company rejected.\n";}
$a=['timestamp'=>'2026-10-27T07:20:00+07:00','employee_user_id'=>'123'];$b=$a;$b['employee_user_id']='456';if(Mekari::eventKey($a)===Mekari::eventKey($b))throw new RuntimeException('Employee key collision');echo "PASS: Simultaneous taps by different employees have independent ledger keys.\nNo network or import request.\n";
