<?php
declare(strict_types=1);

/** Independent, append-only HRMS export. Does not call Talenta or change FlFlag. */
final class HrmsSdfExport {
    /** Attempt the append before acknowledging a stored machine request. DB success is never rolled back by a file error. */
    public static function onReceive(string $requestId,string $sourceIp): array {
        if(!enabled('HRMS_EXPORT_ON_RECEIVE'))return ['status'=>'disabled','lines_added'=>0];
        $audit=new ReceiverAudit(ROOT.'/storage/hrms-on-receive.jsonl');
        try{
            $result=self::run(false,false);
            $audit->append(['request_id'=>$requestId,'source_ip'=>$sourceIp]+$result);
            return $result;
        }catch(Throwable $error){
            try{$audit->append(['request_id'=>$requestId,'source_ip'=>$sourceIp,'status'=>'error','error_class'=>get_class($error),'reason'=>$error->getMessage()]);}catch(Throwable $_ignored){}
            return ['status'=>'error','lines_added'=>0,'reason'=>'Database stored, but HRMS SDF append failed; inspect private HRMS audit before retrying.'];
        }
    }
    public static function line(array $row): string {
        $terminal=(string)$row['FcTermNo'];$card=(string)$row['FcCardNo'];$direction=(string)$row['FcDirection'];
        if(!preg_match('/\A0?[0-9]{2}\z/',$terminal)||!preg_match('/\A[0-9]{1,6}\z/',$card)||!preg_match('/\A[01]\z/',$direction))throw new RuntimeException('HRMS fields do not fit verified-width layout.');
        $date=new DateTimeImmutable(LegacyAttendanceRepository::timestamp($row));
        return substr($terminal,-2).str_pad($card,6,'0',STR_PAD_LEFT).$date->format('YmdHi').$direction."\r\n";
    }
    public static function keys(array $rows): array {
        $counts=[];foreach($rows as $row){$key=LegacyAttendanceRepository::fingerprint($row);$counts[$key]=($counts[$key]??0)+1;}return $counts;
    }
    private static function tail($file,int $size): string { $start=max(0,$size-65536);fseek($file,$start);return hash('sha256',stream_get_contents($file,$size-$start)); }
    /** Pending append is journaled before touching target; safe partial-write recovery. */
    public static function append(array $rows,string $target,string $statePath,bool $baseline=false): array {
        $lock=fopen($statePath.'.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('HRMS exporter already running.');$file=null;
        try{
            foreach($rows as $row)self::line($row); // Validate entire input before any file mutation.
            $exists=is_file($target);$file=fopen($target,'c+b');if(!$file||!flock($file,LOCK_EX))throw new RuntimeException('HRMS target unavailable.');
            $size=fstat($file)['size'];$state=is_file($statePath)?jsonFile($statePath):null;
            if($baseline&&$state!==null)throw new RuntimeException('HRMS state already exists; baseline cannot overwrite prior export history.');
            if($state===null){
                if($size>0&&!$baseline)throw new RuntimeException('Existing SDF requires reviewed baseline; no automatic historical replay.');
                $state=['target'=>str_replace('\\','/',realpath($target)),'counts'=>$baseline?self::keys($rows):[],'size'=>$size,'tail'=>self::tail($file,$size)];
                saveJson($statePath,$state);
            }
            if($state['target']!==str_replace('\\','/',realpath($target)))throw new RuntimeException('HRMS target differs from state.');
            if(isset($state['pending'])){
                $p=$state['pending'];$bytes=base64_decode($p['bytes'],true);$written=$size-$p['offset'];
                if($bytes===false||$written<0||$written>strlen($bytes))throw new RuntimeException('HRMS pending target size differs; reconcile manually.');
                if(self::tail($file,$p['offset'])!==$state['tail'])throw new RuntimeException('HRMS existing prefix changed during pending write.');
                fseek($file,$p['offset']);if(stream_get_contents($file)!==substr($bytes,0,$written))throw new RuntimeException('HRMS pending data differs; another writer may be active.');
                fseek($file,0,SEEK_END);$left=substr($bytes,$written);if($left!==''&&fwrite($file,$left)!==strlen($left))throw new RuntimeException('HRMS partial append; journal retained.');
                if(!fflush($file)||(function_exists('fsync')&&!fsync($file)))throw new RuntimeException('HRMS flush failed; journal retained.');
                $state['counts']=$p['counts'];$state['size']=$p['offset']+strlen($bytes);$state['tail']=self::tail($file,$state['size']);unset($state['pending']);saveJson($statePath,$state);$size=$state['size'];
            }
            if($size!==$state['size']||self::tail($file,$size)!==$state['tail'])throw new RuntimeException('SDF changed outside this exporter. Stop/reconcile legacy writer before continuing.');
            $counts=self::keys($rows);foreach($state['counts'] as $key=>$count)if(($counts[$key]??0)<$count)throw new RuntimeException('HRMS source rows disappeared/changed; refusing replay.');
            $seen=[];$payload='';$added=0;
            foreach($rows as $row){$key=LegacyAttendanceRepository::fingerprint($row);$seen[$key]=($seen[$key]??0)+1;if($seen[$key]<=($state['counts'][$key]??0))continue;$payload.=self::line($row);$added++;}
            if($payload!==''){
                $state['pending']=['offset'=>$size,'bytes'=>base64_encode($payload),'counts'=>$counts];saveJson($statePath,$state);
                fseek($file,0,SEEK_END);if(fwrite($file,$payload)!==strlen($payload)||!fflush($file)||(function_exists('fsync')&&!fsync($file)))throw new RuntimeException('HRMS write incomplete; journal retained.');
                $state['counts']=$counts;$state['size']=$size+strlen($payload);$state['tail']=self::tail($file,$state['size']);unset($state['pending']);saveJson($statePath,$state);
            }
            return ['status'=>'exported','lines_added'=>$added,'baseline'=>$baseline,'target'=>$target];
        }finally{if(is_resource($file)){flock($file,LOCK_UN);fclose($file);}flock($lock,LOCK_UN);fclose($lock);}
    }
    public static function run(bool $preview=false,bool $baseline=false): array {
        if(!$preview&&!enabled('HRMS_EXPORT_ENABLED'))return ['status'=>'disabled','lines_added'=>0];
        if(!$preview&&!enabled('HRMS_DIRECTION_CONFIRMED'))throw new RuntimeException('Confirm HRMS final digit source FcDirection before enabling export.');
        $from=env('HRMS_EXPORT_FROM');$date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$from,new DateTimeZone(env('ATTENDANCE_TIMEZONE','Asia/Jakarta')));
        if(!$date||$date->format('Y-m-d H:i:s')!==$from)throw new RuntimeException('Set HRMS_EXPORT_FROM=YYYY-MM-DD HH:MM:SS to a reviewed cutover timestamp.');
        $pdo=DatabaseConnection::getConnection('reader');$repo=new LegacyAttendanceRepository($pdo);$repo->inspect();$q=[Database::class,'quote'];$schema=Database::driver()==='mysql'?env('DB_DATABASE'):env('LEGACY_SCHEMA','public');
        $sql='SELECT '.implode(',',array_map($q,LegacyAttendanceRepository::FIELDS)).' FROM '.$q($schema).'.'.$q('tbl_rawdata').' WHERE '.$q('FdDate').' >= :date ORDER BY '.$q('FdDate').','.$q('FcTime').','.$q('FcTermNo').','.$q('FcCardNo').','.$q('FcDirection').' LIMIT 100001';
        $stmt=$pdo->prepare($sql);$stmt->execute(['date'=>$date->format('Y-m-d')]);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>100000)throw new RuntimeException('HRMS scope exceeds 100000 rows; reviewed state rotation required.');
        $rows=array_values(array_filter($rows,fn($row)=>substr(LegacyAttendanceRepository::timestamp($row),0,19)>=str_replace(' ','T',$from)));
        $lines=array_map([self::class,'line'],$rows);if($preview)return ['status'=>'preview','source_rows'=>count($rows),'last_lines'=>array_slice($lines,-5),'writes'=>false];
        $target=env('HRMS_SDF_PATH');if($target===''||!is_dir(dirname($target)))throw new RuntimeException('HRMS target directory must already exist.');
        $state=ROOT.'/storage/hrms-sdf-'.hash('sha256',Database::driver().'|'.env('DB_HOST').'|'.env('DB_DATABASE').'|'.$target.'|'.$from).'.json';
        $result=self::append($rows,$target,$state,$baseline);
        (new ReceiverAudit(ROOT.'/storage/hrms-export.jsonl'))->append($result);return $result;
    }
}
