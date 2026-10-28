<?php

declare (strict_types=1);
final class WorkCalendarService
{
    public function resolve(string $nik, string $date, ?array $shift, array $calendar): array
    {
        $matches = array_values(array_filter($calendar, fn($r) => (empty($r['employee_nik']) || $r['employee_nik'] === $nik) && $r['from_date'] <= $date && $r['to_date'] >= $date));
        if ($matches) {
            $max = max(array_column($matches, 'priority'));
            $top = array_values(array_filter($matches, fn($r) => (int) $r['priority'] === (int) $max));
            $types = array_unique(array_column($top, 'day_type'));
            if (count($types) !== 1) {
                return ['day_type' => 'unknown', 'source' => 'calendar', 'issues' => ['Conflicting calendar rules at the same priority']];
            }
            if (!in_array($top[0]['day_type'], ['working', 'day_off', 'holiday'], true)) {
                compat_throw(new InvalidArgumentException('Unsupported configured calendar day type.'));
            }
            return ['day_type' => $top[0]['day_type'], 'source' => 'calendar', 'issues' => []];
        }
        if (!$shift) {
            return ['day_type' => 'unknown', 'source' => 'unresolved', 'issues' => ['Working calendar cannot be resolved without a shift or calendar rule']];
        }
        $days = is_array($shift['weekdays']) ? $shift['weekdays'] : json_decode($shift['weekdays'], true, 512, JSON_THROW_ON_ERROR);
        return ['day_type' => in_array((int) (new DateTimeImmutable($date))->format('N'), array_map('intval', $days), true) ? 'working' : 'day_off', 'source' => 'shift_weekdays', 'issues' => []];
    }
}
