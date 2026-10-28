<?php
declare(strict_types=1);
// Read-only signed GET diagnostic. Never imports attendance or prints credentials.
require __DIR__.'/../app/bootstrap.php';
if(PHP_SAPI!=='cli')exit('CLI only');
$card=$argv[1]??'2131';if(!preg_match('/\A[0-9]{1,20}\z/',$card))throw new RuntimeException('Use a numeric employee ID.');
$id=env('MEKARI_CLIENT_ID');$secret=env('MEKARI_CLIENT_SECRET');
if($id===''||$secret==='')throw new RuntimeException('Client ID/Secret not configured.');
if(preg_match('/[\x00-\x1f\x7f"]/',$id.$secret))throw new RuntimeException('Credential contains invalid characters.');
$target='/v2/talenta/v3/employee/employment-info?'.http_build_query(['employee_id'=>$card],'','&',PHP_QUERY_RFC3986);
$date=gmdate('D, d M Y H:i:s').' GMT';$remoteDate='';$requestId='';$body='';$start=microtime(true);
$curl=curl_init('https://api.mekari.com'.$target);
$options=[CURLOPT_HTTPGET=>true,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Date: '.$date,'Authorization: '.Mekari::signRequest($date,$id,$secret,'GET',$target),'Accept: application/json'],CURLOPT_WRITEFUNCTION=>function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>function($handle,string $line)use(&$remoteDate,&$requestId):int{if(preg_match('/^date:\s*(.+)$/i',trim($line),$m))$remoteDate=$m[1];if(preg_match('/^x-request-id:\s*([a-zA-Z0-9_-]{1,120})$/i',trim($line),$m))$requestId=$m[1];return strlen($line);}];
$ca=env('MEKARI_CA_BUNDLE',ini_get('curl.cainfo')?:'');if($ca===''&&is_file('C:/xampp/apache/bin/curl-ca-bundle.crt'))$ca='C:/xampp/apache/bin/curl-ca-bundle.crt';if($ca!=='')$options[CURLOPT_CAINFO]=$ca;
curl_setopt_array($curl,$options);$ok=curl_exec($curl);$http=curl_getinfo($curl,CURLINFO_HTTP_CODE);$errno=curl_errno($curl);curl_close($curl);$end=microtime(true);
$result=['php'=>PHP_VERSION,'http'=>$http,'curl_errno'=>$errno,'request_id'=>$requestId,'client_id_fingerprint'=>substr(hash('sha256',$id),0,16),'credential_has_outer_whitespace'=>$id!==trim($id)||$secret!==trim($secret),'local_request_utc'=>$date,'remote_http_date'=>$remoteDate,'response_sha256'=>hash('sha256',$body),'import_sent'=>false];
$remote=strtotime($remoteDate);if($remote!==false)$result['approx_remote_minus_local_seconds']=(int)round($remote-(($start+$end)/2));
$json=json_decode($body,true);$message=is_array($json)?($json['message']??$json['error']??''):'';
if(is_string($message)){
    foreach(['MEKARI_CLIENT_ID','MEKARI_CLIENT_SECRET','MEKARI_IMPORT_TOKEN','APP_KEY'] as $key){$value=env($key);if($value!=='')$message=str_replace($value,'[REDACTED]',$message);}
    $result['api_message']=substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$message),0,300);
}
if($ok!==false&&$http===200)$result['authentication']='accepted';
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
