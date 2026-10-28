<?php

declare (strict_types=1);
final class AttendanceShiftResolver
{
    public function resolve(array $mapping, string $date, array $shifts, array $assignments): array
    {
        $matches = array_values(array_filter($assignments, fn($a) => $a['employee_nik'] === $mapping['employee_nik'] && $a['from_date'] <= $date && $a['to_date'] >= $date));
        $issues = [];
        $source = 'employee_mapping';
        $code = $mapping['shift_code'] ?? null;
        if ($matches) {
            $priority = max(array_column($matches, 'priority'));
            $top = array_values(array_filter($matches, fn($a) => (int) $a['priority'] === (int) $priority));
            $codes = array_unique(array_map(fn($a) => $a['shift_code'] ?? '', $top));
            if (count($codes) !== 1) {
                $issues[] = 'Conflicting shift assignments at the same priority';
                $code = null;
            } else {
                $code = $top[0]['shift_code'];
            }
            $source = 'roster';
        }
        $master = array_column($shifts, null, 'code');
        $shift = $code !== null ? $master[$code] ?? null : null;
        if (!$shift) {
            $issues[] = 'No configured shift';
        }
        return ['shift' => $shift, 'source' => $source, 'issues' => $issues, 'assignments' => $matches];
    }
    public static function bounds(array $shift, string $date): array
    {
        if (($shift['timezone'] ?? '') !== 'Asia/Jakarta') {
            compat_throw(new InvalidArgumentException('Attendance engine requires the configured shift timezone Asia/Jakarta.'));
        }
        $tz = new DateTimeZone('Asia/Jakarta');
        $start = new DateTimeImmutable($date . ' ' . $shift['start_time'], $tz);
        $end = new DateTimeImmutable($date . ' ' . $shift['end_time'], $tz);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return ['start' => $start, 'end' => $end, 'entry_from' => $start->modify('-' . (int) $shift['in_before_minutes'] . ' minutes'), 'entry_to' => $start->modify('+' . (int) $shift['in_after_minutes'] . ' minutes'), 'exit_from' => $end->modify('-' . (int) $shift['out_before_minutes'] . ' minutes'), 'exit_to' => $end->modify('+' . (int) $shift['out_after_minutes'] . ' minutes')];
    }
}
