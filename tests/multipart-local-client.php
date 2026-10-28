<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
// Requires the loopback-only upload fixture described in PHP74_COMPATIBILITY.md.
$csv="Date,Time,Employee_ID,Direction,Terminal_Number,Flag\r\n2001-01-01,07:20:49,TEST,1,004,0\r\n";$temp=null;
try{
    $file=Mekari::multipartCsv($csv,$temp);$c=curl_init('http://127.0.0.1:8075/upload.php');
    curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['marker'=>'OFFLINE-COMPAT-TEST','file'=>$file],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'']);
    $body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);$result=json_decode((string)$body,true);
    if($code!==200||($result['sha256']??'')!==hash('sha256',$csv)||($result['name']??'')!=='attendance.csv'||($result['type']??'')!=='text/csv')throw new RuntimeException('Local multipart transport mismatch.');
    echo 'PASS: PHP '.PHP_VERSION.' multipart CSV delivered byte-exact to loopback fixture. No Talenta request.'.PHP_EOL;
}finally{if($temp!==null&&is_file($temp))unlink($temp);}
