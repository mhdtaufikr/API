<?php
declare(strict_types=1);

final class AttendanceStatusService {
    private array $master;
    public function __construct(array $statuses) { $this->master=array_column($statuses,null,'condition_key'); }
    public function code(string $condition): ?string { return $this->master[$condition]['code']??null; }
    public function resolve(array $conditions): array {
        $rows=[];$missing=[];
        foreach (array_unique($conditions) as $condition) {
            if (!isset($this->master[$condition]))$missing[]='Attendance status master is missing condition: '.$condition;
            else $rows[]=$this->master[$condition];
        }
        usort($rows,fn($a,$b)=>(int)$b['priority']<=>(int)$a['priority']?:strcmp($a['code'],$b['code']));
        if (count($rows)>1&&(int)$rows[0]['priority']===(int)$rows[1]['priority']&&$rows[0]['code']!==$rows[1]['code'])$missing[]='Attendance status priority tie requires review';
        return ['code'=>$missing?($this->code('manual_review')):($rows[0]['code']??null),'codes'=>array_column($rows,'code'),'issues'=>$missing];
    }
}
