<?php
declare(strict_types=1);
putenv('DRY_RUN=true');
require __DIR__.'/../app/bootstrap.php';
function compatCheck(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);echo 'PASS: '.$label.PHP_EOL; }
compatCheck(str_contains('abc','')&&str_contains('abc','b')&&!str_contains('abc','z'),'String polyfill semantics');
compatCheck(array_is_list([])&&array_is_list(['a','b'])&&!array_is_list([1=>'a']),'List polyfill semantics');
compatCheck(compat_match(1,[[['1'],fn()=>'wrong'],[[1],fn()=>'strict']])==='strict','Match uses strict equality');
compatCheck(compat_match('ok',[[['ok'],fn()=>'yes'],[null,fn()=>compat_throw(new RuntimeException('must stay lazy'))]])==='yes','Unselected match arm does not execute');
$input=['cardno'=>'0040002T17992026102607204910','d'=>'2026/10/26','h'=>'07:20','m'=>'004'];
$event=(new MachineRequestParser())->parse($input);
compatCheck($event['event_datetime']==='2026-10-26T07:20:49+07:00'&&$event['card_number']==='1799','Actual machine log parses original seconds under this runtime');
$f=fopen('php://temp','r+');compat_csv_row($f,['a,b','x"y',"line\nnext"]);rewind($f);$bytes=stream_get_contents($f);rewind($f);$decoded=fgetcsv($f,0,',','"','');fclose($f);
compatCheck(substr($bytes,-2)==="\r\n"&&$decoded===['a,b','x"y',"line\nnext"],'CSV CRLF and quoted embedded content round-trip');
$csv=Mekari::importCsv([['nik'=>'2120','badge_number'=>'2120','timestamp'=>'2026-10-27T07:20:49+07:00','direction'=>'UNKNOWN','raw_direction'=>'1','terminal_id'=>'004','source_flag'=>0]],Mekari::contract());
compatCheck($csv==="Date,Time,Employee_ID,Direction,Terminal_Number,Flag\r\n2026-10-27,07:20:49,2120,1,004,0\r\n",'Successful Attendance MKM CSV remains byte-exact');
$temp=null;
try{$upload=Mekari::multipartCsv($csv,$temp);compatCheck($upload instanceof CURLFile||get_class($upload)==='CURLStringFile','Multipart attachment supported');if($upload instanceof CURLFile){compatCheck($temp!==null&&file_get_contents($temp)===$csv,'PHP 7.4 private temporary attachment is exact');}}
finally{if($temp!==null&&is_file($temp))unlink($temp);}
$key='base64:'.base64_encode(random_bytes(32));$cipher=new CredentialCipher($key);$encoded=$cipher->encrypt('TEST-ONLY','MEKARI_IMPORT_TOKEN');compatCheck($cipher->decrypt($encoded,'MEKARI_IMPORT_TOKEN')==='TEST-ONLY','Encrypted credential round-trip works');
echo 'Runtime PHP '.PHP_VERSION.'; no Talenta request.'.PHP_EOL;
