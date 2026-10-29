<?php

declare (strict_types=1);
class NoPendingLegacyAttendanceException extends RuntimeException
{
}

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
            compat_throw(new NoPendingLegacyAttendanceException('Tidak ada item FlFlag=0 pada pilihan ini.'));
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
    public static function assertInteractiveEmployeeLimit(array $rows): void
    {
        if (count(array_unique(array_column($rows, 'FcCardNo'))) > 100) {
            compat_throw(new RuntimeException('Maksimal 100 karyawan per preview. Persempit tanggal/filter.'));
        }
    }
    public function preview(string $from, string $to, string $card, string $selection, bool $skipInvalidIdentities = false): array
    {
        $selectedRows = self::select($this->source->rows($from, $to, $card===''?null:$card), $selection);
        return $this->prepareRows($selectedRows, $skipInvalidIdentities, true, false);
    }
    public function previewScheduled(array $scope): array
    {
        foreach (['from', 'to', 'cards', 'cursor', 'row_limit'] as $key) {
            if (!array_key_exists($key, $scope)) {
                compat_throw(new InvalidArgumentException('Invalid scheduled scope.'));
            }
        }
        $page = $this->source->scheduledRowsPage($scope['from'], $scope['to'], $scope['cards'], $scope['cursor'], $scope['row_limit']);
        if (!$page['rows']) {
            compat_throw(new NoPendingLegacyAttendanceException('Scheduled page no longer contains pending rows.'));
        }
        $held = [];
        $eligible = [];
        foreach ($page['rows'] as $row) {
            if ($row['identity_ambiguous']) {
                $held[] = ['rows' => (int) $row['identical_rows'], 'reason' => 'Ambiguous identical source tuple'];
                continue;
            }
            $eligible[] = $row;
        }
        $plan = $this->prepareRows($eligible, true, false, true);
        $plan['held'] = array_merge($held, $plan['held']);
        $plan['source_count'] = $page['record_count'];
        $plan['hash'] = $this->planHash($plan['rows'], $plan['events'], $plan['csv'], $plan['skipped'], $plan['held']);
        return $plan;
    }
    private function prepareRows(array $selectedRows, bool $skipInvalidIdentities, bool $interactiveLimits, bool $holdAttempted): array
    {
        $rows = $selectedRows;
        $issues = [];
        $skipped = [];
        $held = [];
        $events = [];
        $csv = '';
        try {
            $maps=[];$cards=array_unique(array_column($rows,'FcCardNo'));
            if($interactiveLimits)self::assertInteractiveEmployeeLimit($rows);
            $started=microtime(true);
            foreach($cards as $number){
                if(microtime(true)-$started>60)throw new RuntimeException('Validasi identitas belum selesai. Buka preview lagi untuk melanjutkan cache; belum ada import dikirim.');
                try {
                    $maps[$number]=TalentaEmployeeResolver::resolve((string)$number);
                } catch (UnsendableTalentaIdentityException $error) {
                    if (!$skipInvalidIdentities) throw $error;
                    $skipped[] = [
                        'card' => (string) $number,
                        'rows' => count(array_filter($selectedRows, fn($row) => (string) $row['FcCardNo'] === (string) $number)),
                        'reason' => $error->getMessage(),
                    ];
                }
            }
            if ($skipInvalidIdentities) {
                $rows = array_values(array_filter($selectedRows, fn($row) => isset($maps[$row['FcCardNo']])));
            }
            foreach ($rows as $row) {
                $map=$maps[$row['FcCardNo']];
                $events[] = ['nik'=>$map['employee_nik'],'employee_user_id'=>$map['talenta_user_id'],'identity_company'=>$map['company_id'],'identity_verified'=>true,'badge_number'=>$map['badge_number'],'timestamp'=>LegacyAttendanceRepository::timestamp($row),'direction'=>'UNKNOWN','raw_direction'=>$row['FcDirection'],'terminal_id'=>$row['FcTermNo'],'source_flag'=>$row['FlFlag']];
            }
            if ($holdAttempted && $events) {
                $attempted = Mekari::attemptedEventKeySet();
                $pendingRows = [];
                $pendingEvents = [];
                foreach ($events as $index => $event) {
                    if (isset($attempted[Mekari::eventKey($event)])) {
                        $held[] = ['rows' => 1, 'reason' => 'Previously attempted event requires reconciliation'];
                        continue;
                    }
                    $pendingRows[] = $rows[$index];
                    $pendingEvents[] = $event;
                }
                $rows = $pendingRows;
                $events = $pendingEvents;
            }
            if ($events) {
                $contract = LegacyTrialSetup::draftContract();
                if (in_array('direction', array_column($contract['csv_columns'], 'source'), true)) {
                    compat_throw(new RuntimeException('Profil CSV meminta IN/OUT tetapi arti FcDirection belum diketahui. Gunakan profil raw yang diverifikasi, jangan menebak arah.'));
                }
                $csv = Mekari::importCsv($events, $contract);
            }
        } catch (Throwable $e) {
            $issues[] = $e->getMessage();
        }
        if (!Mekari::ready(true)) {
            $issues[] = 'Pengiriman belum diaktifkan: verifikasi profil CSV/raw, kredensial, NIK/User ID, DRY_RUN dan izin live terlebih dahulu.';
        }
        $hash = $this->planHash($rows, $events, $csv, $skipped, $held);
        return compact('rows', 'events', 'csv', 'issues', 'skipped', 'held', 'hash');
    }
    private function planHash(array $rows, array $events, string $csv, array $skipped, array $held): string
    {
        $generation = is_file(ROOT . '/storage/trial-generation.json') ? jsonFile(ROOT . '/storage/trial-generation.json') : [];
        return hash('sha256', json_encode([$rows, $events, $csv, $skipped, $held, config(), IntegrationSettings::fingerprint(), $generation], JSON_THROW_ON_ERROR));
    }
    public function send(array $scope, string $hash, bool $skipInvalidIdentities = false): array
    {
        return $this->dispatch(fn(): array => $this->preview($scope[0], $scope[1], $scope[2], $scope[3], $skipInvalidIdentities), $hash);
    }
    public function sendScheduled(array $scope, string $hash): array
    {
        return $this->dispatch(fn(): array => $this->previewScheduled($scope), $hash);
    }
    private function dispatch(callable $preview, string $hash): array
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app') {
            compat_throw(new RuntimeException('Pengiriman hanya tersedia di mode legacy_app.'));
        }
        $lock = fopen(ROOT . '/storage/legacy-manual-send.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            compat_throw(new RuntimeException('Pengiriman lain sedang berjalan.'));
        }
        try {
            $p = $preview();
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
            (new ReceiverAudit(ROOT . '/storage/legacy-dispatch.jsonl'))->append(['status' => $result['status'], 'http' => $result['http'], 'ledger_id' => $result['id'], 'count' => count($p['rows']), 'skipped' => array_sum(array_column($p['skipped'] ?? [], 'rows')), 'held' => array_sum(array_column($p['held'] ?? [], 'rows')), 'flag_updated' => $result['flag_updated']]);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
