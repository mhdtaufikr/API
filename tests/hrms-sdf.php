<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$dir=ROOT.'/storage/hrms-test-'.bin2hex(random_bytes(6));mkdir($dir);$target=$dir.'/ABSENSI.SDF';$state=$dir.'/state.json';$count=0;
function hrmsCheck(bool $ok,string $name):void{global $count;if(!$ok)throw new RuntimeException($name);$count++;echo 'PASS: '.$name.PHP_EOL;}
function hrmsRefuse(callable $fn,string $name):void{try{$fn();}catch(RuntimeException $e){hrmsCheck(true,$name);return;}throw new LogicException($name);}
$r=['FdDate'=>'2026-10-27','FcTime'=>'16:04:19','FcCardNo'=>'2038','FcTermNo'=>'003','FcDirection'=>'1','FlFlag'=>0];
try{
    hrmsCheck(HrmsSdfExport::line($r)==="030020382026102716041\r\n",'Verified-width sample matches including CRLF');
    $rows=[$r,$r];$second=$r;$second['FcTime']='16:04:22';$rows[]=$second;
    hrmsCheck(HrmsSdfExport::append($rows,$target,$state)['lines_added']===3,'All physical duplicate occurrences and same-minute taps preserved');
    hrmsCheck(HrmsSdfExport::append($rows,$target,$state)['lines_added']===0,'Repeated run appends zero duplicate occurrences');
    foreach($rows as &$row)$row['FlFlag']=1;unset($row);
    hrmsCheck(HrmsSdfExport::append($rows,$target,$state)['lines_added']===0,'Talenta flag changes do not repeat HRMS export');
    $next=$r;$next['FcTime']='17:00:00';$rows[]=$next;
    $saved=jsonFile($state);$payload=HrmsSdfExport::line($next);$saved['pending']=['offset'=>filesize($target),'bytes'=>base64_encode($payload),'counts'=>HrmsSdfExport::keys($rows)];saveJson($state,$saved);file_put_contents($target,substr($payload,0,7),FILE_APPEND);clearstatcache();
    HrmsSdfExport::append($rows,$target,$state);hrmsCheck(substr(file_get_contents($target),-strlen($payload))===$payload&&strlen(file_get_contents($target))===4*23,'Interrupted partial append recovered exactly once');
    hrmsRefuse(fn()=>HrmsSdfExport::append([$r],$target,$state),'Source deletion/change cannot cause silent replay');
    file_put_contents($target,"EXTERNAL\r\n",FILE_APPEND);clearstatcache();hrmsRefuse(fn()=>HrmsSdfExport::append($rows,$target,$state),'External writer modification detected');
    $existing=$dir.'/existing.sdf';file_put_contents($existing,"OLD\r\n");hrmsRefuse(fn()=>HrmsSdfExport::append($rows,$existing,$dir.'/existing.json'),'Nonempty historical target requires reviewed baseline');
    hrmsCheck(HrmsSdfExport::append($rows,$existing,$dir.'/baseline.json',true)['lines_added']===0&&file_get_contents($existing)==="OLD\r\n",'Explicit baseline preserves existing bytes and skips reviewed historical scope');
    $bad=$r;$bad['FcTermNo']='123';hrmsRefuse(fn()=>HrmsSdfExport::line($bad),'Terminal overflow is not truncated');
    echo $count." HRMS checks passed. Temporary files only; no real SDF, DB flag, or Talenta request.\n";
}finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
