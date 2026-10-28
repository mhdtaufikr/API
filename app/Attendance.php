<?php

declare (strict_types=1);
final class Attendance
{
    public static function sample(): array
    {
        return [['nik' => 'DEMO-001', 'timestamp' => '2026-10-26T08:00:00+07:00', 'direction' => 'IN'], ['nik' => 'DEMO-001', 'timestamp' => '2026-10-26T17:00:00+07:00', 'direction' => 'OUT'], ['nik' => 'DEMO-001', 'timestamp' => '2026-10-26T17:00:00+07:00', 'direction' => 'OUT'], ['nik' => 'DEMO-001', 'timestamp' => '2026-10-27T08:02:00+07:00', 'direction' => 'UNKNOWN']];
    }
    public static function validate(array $rows, string $nik): array
    {
        $seen = [];
        $events = [];
        $issues = [];
        $tz = new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'));
        foreach ($rows as $i => $row) {
            $reason = null;
            if ((string) ($row['nik'] ?? '') !== $nik) {
                $reason = 'Employee does not match the single configured NIK';
            }
            try {
                $raw = (string) ($row['timestamp'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $raw)) {
                    compat_throw(new RuntimeException());
                }
                $time = new DateTimeImmutable($raw, $tz);
                if (compat_datetime_errors()) {
                    compat_throw(new RuntimeException());
                }
                $time = $time->setTimezone($tz);
                if ($time > new DateTimeImmutable('now', $tz)) {
                    $reason = 'Future timestamp';
                }
            } catch (Throwable $_ignored) {
                $reason = 'Invalid timestamp';
                $time = null;
            }
            if (!in_array($row['direction'] ?? '', ['IN', 'OUT'], true)) {
                $reason = 'Unknown direction: requires authoritative device/shift mapping';
            }
            $key = $nik . '|' . (($time !== null ? $time->format('U.u') : null) ?? '') . '|' . ($row['direction'] ?? '');
            if (isset($seen[$key])) {
                $reason = 'Duplicate source event';
            }
            if ($reason !== null) {
                $issues[] = ['row' => $i + 1, 'reason' => $reason];
                continue;
            }
            $seen[$key] = true;
            $events[] = ['nik' => $nik, 'timestamp' => $time->format('c'), 'direction' => $row['direction']];
        }
        usort($events, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
        return ['events' => $events, 'issues' => $issues];
    }
    public static function csv(array $events): string
    {
        $stream = fopen('php://temp', 'r+');
        compat_csv_row($stream, ['NIK', 'Timestamp', 'Direction']);
        foreach ($events as $event) {
            $values = array_values($event);
            foreach ($values as &$value) {
                if (preg_match('/^[=+\-@\t\r]/', $value)) {
                    $value = "'" . $value;
                }
            }
            unset($value);
            compat_csv_row($stream, $values);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }
}
