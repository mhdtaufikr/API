<?php

declare (strict_types=1);
final class LegacyManualSend
{
    private LegacyAttendanceRepository $source;
    public function __construct(LegacyAttendanceRepository $source)
    {
        $this->source = $source;
    }
    public static function select(array $rows, string $selection): array
    {
        if ($selection !== 'all' && !preg_match('/\A[a-f0-9]{64}\z/', $selection)) {
            compat_throw(new InvalidArgumentException('Pilihan item tidak valid.'));
        }
        $chosen = array_values(array_filter($rows, fn($r) => $selection === 'all' ? (string) $r['FlFlag'] === '0' : $r['value_group_hash'] === $selection));
        if (!$chosen) {
            compat_throw(new RuntimeException('Tidak ada item FlFlag=0 pada pilihan ini.'));
        }
        foreach ($chosen as $row) {
            if ((string) $row['FlFlag'] !== '0') {
                compat_throw(new RuntimeException('Item sudah dikirim atau flag bukan 0.'));
            }
            if ($row['identity_ambiguous']) {
                compat_throw(new RuntimeException('Ada baris identik tanpa ID unik. Pilihan ditahan; tidak dikirim sebagian diam-diam.'));
            }
            if(!is_string($row['FcCardNo'])||$row['FcCardNo']==='')throw new RuntimeException('Kartu sumber tidak valid.');
        }
        return $chosen;
    }
    public function preview(string $from, string $to, string $card, string $selection): array
    {
        $rows = self::select($this->source->rows($from, $to, $card===''?null:$card), $selection);
        $issues = [];
        $events = [];
        $csv = '';
        try {
            $maps=[];$cards=array_unique(array_column($rows,'FcCardNo'));
            if(count($cards)>100)throw new RuntimeException('Maksimal 100 karyawan per preview. Persempit tanggal/filter.');
            $started=microtime(true);
            foreach($cards as $number){if(microtime(true)-$started>60)throw new RuntimeException('Validasi identitas belum selesai. Buka preview lagi untuk melanjutkan cache; belum ada import dikirim.');$maps[$number]=TalentaEmployeeResolver::resolve((string)$number);}
            foreach ($rows as $row) {
                $map=$maps[$row['FcCardNo']];
                $events[] = ['nik'=>$map['employee_nik'],'employee_user_id'=>$map['talenta_user_id'],'identity_company'=>$map['company_id'],'identity_verified'=>true,'badge_number'=>$map['badge_number'],'timestamp'=>LegacyAttendanceRepository::timestamp($row),'direction'=>'UNKNOWN','raw_direction'=>$row['FcDirection'],'terminal_id'=>$row['FcTermNo'],'source_flag'=>$row['FlFlag']];
            }
            $contract = LegacyTrialSetup::draftContract();
            if (in_array('direction', array_column($contract['csv_columns'], 'source'), true)) {
                compat_throw(new RuntimeException('Profil CSV meminta IN/OUT tetapi arti FcDirection belum diketahui. Gunakan profil raw yang diverifikasi, jangan menebak arah.'));
            }
            $csv = Mekari::importCsv($events, $contract);
        } catch (Throwable $e) {
            $issues[] = $e->getMessage();
        }
        if (!Mekari::ready(true)) {
            $issues[] = 'Pengiriman belum diaktifkan: verifikasi profil CSV/raw, kredensial, NIK/User ID, DRY_RUN dan izin live terlebih dahulu.';
        }
        $generation = is_file(ROOT . '/storage/trial-generation.json') ? jsonFile(ROOT . '/storage/trial-generation.json') : [];
        $hash = hash('sha256', json_encode([$rows, $events, $csv, config(), IntegrationSettings::fingerprint(), $generation], JSON_THROW_ON_ERROR));
        return compact('rows', 'events', 'csv', 'issues', 'hash');
    }
    public function send(array $scope, string $hash): array
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app') {
            compat_throw(new RuntimeException('Pengiriman hanya tersedia di mode legacy_app.'));
        }
        $lock = fopen(ROOT . '/storage/legacy-manual-send.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            compat_throw(new RuntimeException('Pengiriman lain sedang berjalan.'));
        }
        try {
            $p = $this->preview(...$scope);
            if ($p['issues']) {
                compat_throw(new RuntimeException(implode(' ', $p['issues'])));
            }
            if (!hash_equals($hash, $p['hash'])) {
                compat_throw(new RuntimeException('Data/config berubah. Buka preview baru.'));
            }
            $result = Mekari::submit($p['events'], true);
            if ($result['status'] === 'submitted_pending_reconciliation') {
                try {
                    $this->source->markAccepted($p['rows']);
                    $result['flag_updated'] = true;
                } catch (Throwable $_ignored) {
                    $result['flag_updated'] = false;
                    $result['response'] .= ' API menerima, tetapi update FlFlag gagal. Jangan kirim ulang; rekonsiliasi diperlukan.';
                }
            } else {
                $result['flag_updated'] = false;
            }
            (new ReceiverAudit(ROOT . '/storage/legacy-dispatch.jsonl'))->append(['status' => $result['status'], 'http' => $result['http'], 'ledger_id' => $result['id'], 'count' => count($p['rows']), 'flag_updated' => $result['flag_updated']]);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
