<?php
declare(strict_types=1);
final class TalentaEmployeeResolver {
    public static function normalize(array $body,string $card): array {
        $items=$body['data']['employment']??[];if(isset($items['employee_id']))$items=[$items];$found=[];
        foreach($items as $row)if(is_array($row)&&(string)($row['employee_id']??'')===$card&&(string)($row['company_id']??'')===env('MEKARI_COMPANY_ID')){
            if((string)($row['barcode']??'')!==$card)throw new RuntimeException('Barcode Talenta berbeda untuk kartu '.$card.'. Perlu mapping eksplisit.');
            if(!preg_match('/\A[1-9][0-9]*\z/',(string)($row['id']??'')))throw new RuntimeException('User ID karyawan tidak valid.');
            $found[]=['verified'=>true,'card_number'=>$card,'employee_nik'=>(string)$row['employee_id'],'talenta_user_id'=>(string)$row['id'],'badge_number'=>(string)$row['barcode'],'company_id'=>(string)$row['company_id']];
        }
        if(count($found)!==1)throw new RuntimeException('Kartu '.$card.' tidak ditemukan secara unik di company Talenta.');return $found[0];
    }
    public static function resolve(string $card): array {
        if(!extension_loaded('curl')||env('MEKARI_BASE_URL')!=='https://api.mekari.com')throw new RuntimeException('cURL/API host Mekari belum sesuai konfigurasi.');
        if($card===''||strlen($card)>50)throw new RuntimeException('Nomor kartu kosong/tidak valid.');
        $path=ROOT.'/storage/employee-identity-cache.json';$context=hash('sha256',env('MEKARI_COMPANY_ID').'|'.IntegrationSettings::fingerprint());$cache=is_file($path)?jsonFile($path):[];
        $saved=$cache[$context][$card]??null;if($saved&&$saved['expires']>time())return $saved['mapping'];
        $id=env('MEKARI_CLIENT_ID');$secret=env('MEKARI_CLIENT_SECRET');if($id===''||$secret===''||preg_match('/[\x00-\x1f\x7f"]/',$id.$secret))throw new RuntimeException('Kredensial Mekari belum valid.');
        $target='/v2/talenta/v3/employee/employment-info?'.http_build_query(['employee_id'=>$card],'','&',PHP_QUERY_RFC3986);$date=gmdate('D, d M Y H:i:s').' GMT';$body='';$c=curl_init('https://api.mekari.com'.$target);
        $options=[CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Date: '.$date,'Authorization: '.Mekari::signRequest($date,$id,$secret,'GET',$target),'Accept: application/json'],CURLOPT_WRITEFUNCTION=>function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>1048576)return 0;$body.=$chunk;return strlen($chunk);}];
        $ca=env('MEKARI_CA_BUNDLE',ini_get('curl.cainfo')?:'');if($ca===''&&is_file('C:/xampp/apache/bin/curl-ca-bundle.crt'))$ca='C:/xampp/apache/bin/curl-ca-bundle.crt';if($ca!=='')$options[CURLOPT_CAINFO]=$ca;
        curl_setopt_array($c,$options);$ok=curl_exec($c);$http=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($ok===false||$http!==200)throw new RuntimeException('Validasi kartu '.$card.' gagal (HTTP '.$http.'). Belum ada import dikirim.');
        $map=self::normalize(json_decode($body,true,512,JSON_THROW_ON_ERROR),$card);
        $lock=fopen(ROOT.'/storage/employee-identity-cache.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cache identitas tidak tersedia.');
        try{$cache=is_file($path)?jsonFile($path):[];$cache[$context][$card]=['mapping'=>$map,'expires'=>time()+86400];saveJson($path,$cache);}finally{flock($lock,LOCK_UN);fclose($lock);}return $map;
    }
}
