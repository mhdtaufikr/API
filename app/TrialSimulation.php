<?php

declare (strict_types=1);
final class TrialSimulation
{
    public const CARD = '2120';
    public static function parse(array $input): array
    {
        $event = (new MachineRequestParser())->parse($input);
        if ($event['card_number'] !== self::CARD) {
            compat_throw(new InvalidArgumentException('Simulasi manual hanya diizinkan untuk kartu 2120.'));
        }
        return $event;
    }
    public static function prepare(AttendanceRepository $repo, array $event): array
    {
        $held = ['status' => 'waiting_validation', 'message' => 'Tersimpan lokal, belum dikirim. Lengkapi pemetaan kartu 2120, NIK trial, arah mesin, dan shift sebelum masuk antrean kirim.'];
        $nik = env('ATTENDANCE_TEST_NIK');
        if ($nik === '') {
            return $held;
        }
        $date = (new DateTimeImmutable($event['event_datetime']))->setTimezone(new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta')))->format('Y-m-d');
        $snapshot = $repo->snapshot($date, $date, $nik);
        $maps = array_values(array_filter($snapshot['mappings'], fn($map) => $map['card_number'] === self::CARD && $map['employee_nik'] === $nik));
        if (count($maps) !== 1 || count($snapshot['mappings']) !== 1) {
            return $held;
        }
        $engine = new AttendanceEngineRepository($repo->db());
        if ($engine->installed()) {
            $summary = (new AttendanceProcessingCommand($engine, $repo, new AttendanceProcessor()))->run($date, $date, $nik);
            if ($summary['processing_errors']) {
                return ['status' => 'waiting_validation', 'message' => 'Data tersimpan, tetapi pengolahan absensi perlu diperiksa admin. Belum dikirim.'];
            }
        }
        $groups = (new AttendanceProcessor())->process($snapshot, $date, $date, $nik);
        $queued = false;
        $issues = [];
        foreach ($groups as $group) {
            if ($group['card_number'] !== self::CARD) {
                continue;
            }
            if ($repo->enqueue($group)) {
                $queued = true;
            }
            $issues = array_merge($issues, $group['issues']);
        }
        if ($queued) {
            return ['status' => 'pending_review', 'message' => 'Masuk antrean review. Buka Data Absensi untuk review CSV dan kirim manual. Belum ada request ke Talenta.'];
        }
        return ['status' => 'waiting_validation', 'message' => $issues ? 'Data tersimpan; belum siap dikirim: ' . implode('; ', array_unique($issues)) : 'Data tersimpan. Periksa antrean yang sudah ada atau validasi absensi; belum ada request ke Talenta.'];
    }
}
