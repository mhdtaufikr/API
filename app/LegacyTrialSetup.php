<?php

declare (strict_types=1);
final class LegacyTrialSetup
{
    public static function draftContract(): array
    {
        return Mekari::contract();
    }
    public static function activate(string $dateFormat, string $timeFormat, string $confirmation, bool $approved): void
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app' || $confirmation !== 'AKTIFKAN 2120' || !$approved) {
            compat_throw(new RuntimeException('Ketik AKTIFKAN 2120 dan konfirmasi profil CSV.'));
        }
        if ($dateFormat !== 'Y-m-d' || $timeFormat !== 'H:i:s') {
            compat_throw(new RuntimeException('Format harus sesuai profil Attendance MKM: Y-m-d dan H:i:s pada kolom terpisah.'));
        }
        $map = jsonFile(ROOT . '/config/legacy-trial-map.json');
        if (($map['verified'] ?? false) !== true || ($map['card_number'] ?? '') !== '2120' || ($map['company_id'] ?? '') !== env('MEKARI_COMPANY_ID') || empty($map['employee_nik']) || empty($map['badge_number']) || empty($map['talenta_user_id'])) {
            compat_throw(new RuntimeException('Identitas kartu belum diverifikasi ke company Talenta.'));
        }
        $lock = fopen(ROOT . '/storage/trial-setup.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            compat_throw(new RuntimeException('Konfigurasi sedang dikunci.'));
        }
        try {
            $contract = self::draftContract();
            if (array_keys($contract['csv_columns']) !== ['Date', 'Time', 'Employee_ID', 'Direction', 'Terminal_Number', 'Flag'] || ($contract['csv_columns']['Date']['format'] ?? '') !== 'Y-m-d' || ($contract['csv_columns']['Time']['format'] ?? '') !== 'H:i:s' || ($contract['attendance_machine_profile']['name'] ?? '') !== 'Attendance MKM') {
                compat_throw(new RuntimeException('Kontrak berbeda dari profil Attendance MKM untuk Import Token ini.'));
            }
            $old = file_get_contents(ROOT . '/.env');
            if ($old === false) {
                compat_throw(new RuntimeException('Tidak dapat membaca .env.'));
            }
            $stamp = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
            if (!copy(ROOT . '/.env', ROOT . '/storage/trial-env-before-' . $stamp . '.backup') || !copy(ROOT . '/config/contract.json', ROOT . '/storage/trial-contract-before-' . $stamp . '.json')) {
                compat_throw(new RuntimeException('Backup konfigurasi gagal.'));
            }
            $values = ['ATTENDANCE_TEST_NIK' => $map['employee_nik'], 'TALENTA_USER_ID' => $map['talenta_user_id'], 'TALENTA_LEGACY_RAW_CONFIRMED' => 'true', 'TALENTA_CSV_PROFILE_CONFIRMED' => 'true', 'DRY_RUN' => 'false', 'ALLOW_PRODUCTION_WRITE' => 'true', 'TALENTA_SCHEDULER_ENABLED' => 'false'];
            $content = $old;
            foreach ($values as $key => $value) {
                if (!is_string($value) || preg_match('/[\r\n]/', $value)) {
                    compat_throw(new RuntimeException('Invalid configuration value.'));
                }
                $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
                $content = preg_match($pattern, $content) ? preg_replace_callback($pattern, fn() => $key . '=' . $value, $content) : rtrim($content) . "\n" . $key . '=' . $value . "\n";
            }
            saveJson(ROOT . '/config/contract.json', $contract);
            $temp = ROOT . '/.env.trial-' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temp, $content, LOCK_EX) !== strlen($content) || !rename($temp, ROOT . '/.env')) {
                compat_throw(new RuntimeException('Tidak dapat menyimpan pengaturan trial.'));
            }
            (new ReceiverAudit(ROOT . '/storage/legacy-dispatch.jsonl'))->append(['status' => 'trial_profile_approved', 'card' => '2120', 'date_format' => $dateFormat, 'time_format' => $timeFormat, 'scheduler_enabled' => false]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
