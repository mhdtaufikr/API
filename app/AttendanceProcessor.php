<?php

declare (strict_types=1);
final class AttendanceProcessor
{
    private static function arrayValue($value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
    public static function truth($value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
    /** Existing receiver/queue preview adapter. Uses the same calculation engine as the batch command. */
    public function process(array $snapshot, string $from, string $to, string $testNik): array
    {
        $groups = [];
        foreach ($this->calculate($snapshot, $from, $to) as $record) {
            if ($record['raw_log_count'] === 0) {
                continue;
            }
            $record['clock_in'] = $record['actual_clock_in'];
            $record['clock_out'] = $record['actual_clock_out'];
            $record['attendance_id'] = null;
            foreach ($snapshot['attendance_records'] ?? [] as $stored) {
                if ($stored['employee_nik'] === $record['employee_nik'] && $stored['attendance_date'] === $record['attendance_date']) {
                    $record['attendance_id'] = $stored['id'] ?? null;
                }
            }
            $issues = $record['issues'];
            $map = $record['mapping'];
            if (!self::truth($map['verified'] ?? false)) {
                $issues[] = 'Employee identifier mapping is not verified';
            }
            foreach (['employee_nik', 'talenta_employee_id', 'talenta_user_id', 'badge_number'] as $field) {
                if (!isset($map[$field]) || $map[$field] === null || $map[$field] === '') {
                    $issues[] = 'Missing explicit ' . $field . ' mapping';
                }
            }
            if ($this->protectedDate($snapshot, $record['employee_nik'], $record['attendance_date'])) {
                $issues[] = 'Protected attendance requires HR review before export';
            }
            $record['issues'] = array_values(array_unique($issues));
            $record['events'] = array_values(array_filter($record['events'], fn($event) => in_array($record['source_classifications'][$event['id']] ?? '', ['clock_in', 'clock_out'], true)));
            $record['status'] = $record['issues'] ? 'manual_review' : ($testNik !== '' && $record['employee_nik'] === $testNik ? 'ready_for_review' : 'local_only');
            $record['batch_hash'] = $record['input_hash'];
            $groups[] = $record;
        }
        return $groups;
    }
    public function protectedDate(array $snapshot, string $nik, string $date): bool
    {
        foreach ($snapshot['period_locks'] ?? [] as $lock) {
            if (self::truth($lock['locked']) && (empty($lock['employee_nik']) || $lock['employee_nik'] === $nik) && $lock['from_date'] <= $date && $lock['to_date'] >= $date) {
                return true;
            }
        }
        foreach ($snapshot['approved_requests'] ?? [] as $request) {
            if ($request['employee_nik'] === $nik && $request['attendance_date'] === $date && $request['state'] === 'approved') {
                return true;
            }
        }
        foreach ($snapshot['attendance_records'] ?? [] as $row) {
            if ($row['employee_nik'] === $nik && $row['attendance_date'] === $date && (self::truth($row['hr_manually_corrected']) || self::truth($row['is_confirmed']) || !empty($row['approved_request_id']) || in_array($row['final_status_source'], ['hr_manual', 'approved_request', 'confirmed'], true))) {
                return true;
            }
        }
        return false;
    }
    /** Pure function: no DB mutations, Talenta calls, or replacement of source timestamps. */
    public function calculate(array $snapshot, string $from, string $to, ?DateTimeImmutable $now = null): array
    {
        AttendanceRepository::dateRange($from, $to);
        $tz = new DateTimeZone('Asia/Jakarta');
        $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
        $statusService = new AttendanceStatusService($snapshot['statuses'] ?? []);
        $resolver = new AttendanceShiftResolver();
        $calendarService = new WorkCalendarService();
        $records = [];
        $known = [];
        foreach ($snapshot['mappings'] as $map) {
            $known[(string) $map['card_number']] = true;
            $slots = [];
            $first = (new DateTimeImmutable($from, $tz))->modify('-1 day');
            $last = (new DateTimeImmutable($to, $tz))->modify('+1 day');
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                $resolved = $resolver->resolve($map, $date, $snapshot['shifts'], $snapshot['assignments'] ?? []);
                $calendar = $calendarService->resolve($map['employee_nik'], $date, $resolved['shift'], $snapshot['calendar'] ?? []);
                $slots[$date] = ['date' => $date, 'mapping' => $map, 'resolved' => $resolved, 'calendar' => $calendar, 'bounds' => $resolved['shift'] ? AttendanceShiftResolver::bounds($resolved['shift'], $date) : null, 'events' => [], 'potential_ids' => [], 'review' => []];
            }
            $rows = array_values(array_filter($snapshot['rows'], fn($row) => (string) $row['card_number'] === (string) $map['card_number']));
            $this->assignLogs($rows, $slots, $snapshot['terminals'], $now);
            foreach ($slots as $date => $slot) {
                if ($date >= $from && $date <= $to) {
                    $records[] = $this->finish($slot, $statusService, $now);
                }
            }
        }
        // Unmapped cards remain visible as manual-review raw transactions, never as invented employees.
        $orphans = [];
        foreach ($snapshot['rows'] as $row) {
            if (!isset($known[(string) $row['card_number']])) {
                $date = (new DateTimeImmutable($row['event_datetime']))->setTimezone($tz)->format('Y-m-d');
                if ($date < $from || $date > $to) {
                    continue;
                }
                $key = $row['card_number'] . '|' . $date;
                $orphans[$key] ??= ['date' => $date, 'mapping' => ['card_number' => (string) $row['card_number'], 'employee_nik' => '', 'employee_name' => 'Unmapped card'], 'resolved' => ['shift' => null, 'source' => 'unresolved', 'issues' => ['Card has no employee mapping', 'No configured shift']], 'calendar' => ['day_type' => 'unknown', 'source' => 'unresolved', 'issues' => []], 'bounds' => null, 'events' => [], 'potential_ids' => [], 'review' => []];
                $orphans[$key]['events'][] = $this->event($row, 'UNKNOWN', false, $orphans[$key]['mapping']);
            }
        }
        foreach ($orphans as $slot) {
            $records[] = $this->finish($slot, $statusService, $now);
        }
        usort($records, fn($a, $b) => [$a['employee_nik'], $a['attendance_date'], $a['card_number']] <=> [$b['employee_nik'], $b['attendance_date'], $b['card_number']]);
        return $records;
    }
    private function event(array $row, string $direction, bool $reliable, array $map): array
    {
        return ['id' => (int) $row['id'], 'nik' => $map['employee_nik'] ?? '', 'badge_number' => $map['badge_number'] ?? '', 'timestamp' => (new DateTimeImmutable($row['event_datetime']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('c'), 'direction' => $direction, 'direction_reliable' => $reliable, 'terminal_id' => $row['terminal_id'], 'raw_direction' => $row['direction_code']];
    }
    private function assignLogs(array $rows, array &$slots, array $terminals, DateTimeImmutable $now): void
    {
        $terminals = array_column($terminals, null, 'terminal_id');
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            try {
                if (!is_string($row['event_datetime']) || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $row['event_datetime'])) {
                    compat_throw(new InvalidArgumentException('Invalid timestamp'));
                }
                $time = new DateTimeImmutable($row['event_datetime']);
                if (compat_datetime_errors()) {
                    compat_throw(new InvalidArgumentException('Invalid calendar timestamp'));
                }
            } catch (Throwable $_ignored) {
                foreach ($slots as &$slot) {
                    $slot['review'][] = 'Invalid source timestamp';
                    $slot['potential_ids'][] = (int) $row['id'];
                }
                unset($slot);
                continue;
            }
            $direction = isset($terminals[$row['terminal_id']]) ? self::arrayValue($terminals[$row['terminal_id']]['direction_map'])[$row['direction_code']] ?? 'UNKNOWN' : 'UNKNOWN';
            $reliable = in_array($direction, ['IN', 'OUT'], true);
            $matches = [];
            $broad = [];
            foreach ($slots as $date => $slot) {
                $b = $slot['bounds'];
                if (!$b) {
                    continue;
                }
                $in = $time >= $b['entry_from'] && $time <= $b['entry_to'];
                $out = $time >= $b['exit_from'] && $time <= $b['exit_to'];
                if ($time >= min($b['entry_from'], $b['exit_from']) && $time <= max($b['entry_to'], $b['exit_to'])) {
                    $broad[] = $date;
                }
                if ($reliable) {
                    if ($direction === 'IN' && $in || $direction === 'OUT' && $out) {
                        $matches[] = [$date, $direction];
                    }
                } elseif (self::truth($slot['resolved']['shift']['window_fallback_enabled'] ?? false)) {
                    if ($in) {
                        $matches[] = [$date, 'IN'];
                    }
                    if ($out) {
                        $matches[] = [$date, 'OUT'];
                    }
                }
            }
            if (count($matches) > 1 || !$matches && count($broad) > 1) {
                $dates = $matches ? array_unique(array_column($matches, 0)) : $broad;
                foreach ($dates as $date) {
                    $slots[$date]['review'][] = 'Ambiguous source log: overlapping shift/entry/exit windows';
                    $slots[$date]['potential_ids'][] = (int) $row['id'];
                }
                continue;
            }
            if ($matches) {
                [$date, $selected] = $matches[0];
            } else {
                $date = $broad[0] ?? $time->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d');
                $selected = 'UNKNOWN';
                if (!isset($slots[$date])) {
                    continue;
                }
                $slots[$date]['review'][] = $reliable ? 'Direction tap outside its configured clock window' : 'Unknown direction code ' . $row['direction_code'] . ' at terminal ' . $row['terminal_id'] . '; window fallback unavailable or out of range';
            }
            if ($time > $now) {
                $slots[$date]['review'][] = 'Future timestamp';
                $selected = 'UNKNOWN';
            }
            $slots[$date]['events'][] = $this->event($row, $selected, $reliable, $slots[$date]['mapping']);
        }
    }
    private function candidates(array $events, string $direction, int $duplicateSeconds, array &$classifications): array
    {
        $events = array_values(array_filter($events, fn($event) => $event['direction'] === $direction));
        $clusters = [];
        foreach ($events as $event) {
            $time = (new DateTimeImmutable($event['timestamp']))->getTimestamp();
            $last = array_key_last($clusters);
            if ($last !== null && $time - $clusters[$last]['anchor'] <= $duplicateSeconds) {
                $clusters[$last]['events'][] = $event;
            } else {
                $clusters[] = ['anchor' => $time, 'events' => [$event]];
            }
        }
        $candidates = [];
        foreach ($clusters as $cluster) {
            $pick = $direction === 'IN' ? $cluster['events'][0] : $cluster['events'][array_key_last($cluster['events'])];
            $candidates[] = $pick;
            foreach ($cluster['events'] as $event) {
                $classifications[$event['id']] = $event['id'] === $pick['id'] ? 'candidate_' . strtolower($direction) : 'excluded_duplicate';
            }
        }
        return $candidates;
    }
    private function finish(array $slot, AttendanceStatusService $statuses, DateTimeImmutable $now): array
    {
        $map = $slot['mapping'];
        $shift = $slot['resolved']['shift'];
        $calendar = $slot['calendar'];
        $events = $slot['events'];
        usort($events, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']) ?: $a['id'] <=> $b['id']);
        $review = array_merge($slot['review'], $calendar['issues']);
        $classifications = [];
        foreach ($events as $event) {
            $classifications[$event['id']] = 'review_unclassified';
        }
        $in = $this->candidates($events, 'IN', max(0, (int) ($shift['duplicate_tap_seconds'] ?? 0)), $classifications);
        $out = $this->candidates($events, 'OUT', max(0, (int) ($shift['duplicate_tap_seconds'] ?? 0)), $classifications);
        $clockIn = $in[0] ?? null;
        $clockOut = $out ? $out[array_key_last($out)] : null;
        if (!$clockIn && $clockOut && !$clockOut['direction_reliable']) {
            $review[] = 'Isolated exit-window log lacks reliable clock-out evidence';
            $clockOut = null;
        }
        if ($clockIn && $clockOut && ($clockIn['id'] === $clockOut['id'] || $clockIn['timestamp'] >= $clockOut['timestamp'])) {
            $review[] = 'Clock-out is not after a distinct clock-in';
            $clockIn = null;
            $clockOut = null;
        }
        if ($clockIn) {
            $classifications[$clockIn['id']] = 'clock_in';
        }
        if ($clockOut) {
            $classifications[$clockOut['id']] = 'clock_out';
        }
        $ids = array_values(array_unique(array_merge(array_column($events, 'id'), $slot['potential_ids'])));
        sort($ids);
        $nonWorking = in_array($calendar['day_type'], ['day_off', 'holiday'], true);
        $punch = [];
        $conditions = [];
        $late = null;
        $early = null;
        $work = null;
        $inStatus = $statuses->code('incomplete');
        $outStatus = $statuses->code('incomplete');
        if (!$shift && (!$nonWorking || $ids)) {
            $review = array_merge($review, $slot['resolved']['issues']);
        } elseif (count($slot['resolved']['issues']) > 1) {
            $review = array_merge($review, $slot['resolved']['issues']);
        }
        $closed = $slot['bounds'] ? $now > $slot['bounds']['exit_to'] : $now > new DateTimeImmutable($slot['date'] . ' 23:59:59', new DateTimeZone('Asia/Jakarta'));
        if (!$ids && $nonWorking) {
            $conditions[] = $calendar['day_type'];
            $inStatus = $outStatus = $statuses->code($calendar['day_type']);
            $work = 0;
        } elseif (!$ids && $shift && $calendar['day_type'] === 'working' && $closed) {
            $conditions[] = 'absent';
            $punch = ['Missing clock-in', 'Missing clock-out'];
            $work = 0;
        } else {
            if (!$clockIn) {
                $punch[] = 'Missing clock-in';
            }
            if (!$clockOut) {
                $punch[] = 'Missing clock-out';
            }
            $conditions[] = $clockIn && $clockOut ? $nonWorking ? 'worked_day_off' : 'present' : 'incomplete';
            if ($clockIn) {
                $inStatus = $statuses->code('present');
            }
            if ($clockOut) {
                $outStatus = $statuses->code('present');
            }
        }
        if ($ids && ($clockIn || $clockOut) && $slot['bounds']) {
            $rounding = $shift['minute_rounding'] ?? null;
            if (!in_array($rounding, ['floor', 'ceil'], true)) {
                $review[] = 'Minute rounding policy is not configured';
            } else {
                $quantize = fn(int $seconds) => (int) ($rounding === 'ceil' ? ceil($seconds / 60) : floor($seconds / 60));
                if ($clockIn && $clockOut) {
                    $work = $quantize((new DateTimeImmutable($clockOut['timestamp']))->getTimestamp() - (new DateTimeImmutable($clockIn['timestamp']))->getTimestamp());
                }
                if ($nonWorking) {
                    $late = 0;
                    $early = 0;
                } elseif (($shift['late_grace_minutes'] ?? null) === null || ($shift['early_leave_grace_minutes'] ?? null) === null || !in_array($shift['grace_mode'] ?? null, ['threshold', 'deduct'], true)) {
                    $review[] = 'Attendance grace policy is not configured';
                } else {
                    $lateSeconds = $clockIn ? max(0, (new DateTimeImmutable($clockIn['timestamp']))->getTimestamp() - $slot['bounds']['start']->getTimestamp()) : null;
                    $earlySeconds = $clockOut ? max(0, $slot['bounds']['end']->getTimestamp() - (new DateTimeImmutable($clockOut['timestamp']))->getTimestamp()) : null;
                    $lateGrace = (int) $shift['late_grace_minutes'] * 60;
                    $earlyGrace = (int) $shift['early_leave_grace_minutes'] * 60;
                    $late = $lateSeconds === null ? null : ($lateSeconds <= $lateGrace ? 0 : $quantize($lateSeconds - ($shift['grace_mode'] === 'deduct' ? $lateGrace : 0)));
                    $early = $earlySeconds === null ? null : ($earlySeconds <= $earlyGrace ? 0 : $quantize($earlySeconds - ($shift['grace_mode'] === 'deduct' ? $earlyGrace : 0)));
                    if ($lateSeconds !== null && $lateSeconds > $lateGrace) {
                        $conditions[] = 'late';
                        $inStatus = $statuses->code('late');
                    }
                    if ($earlySeconds !== null && $earlySeconds > $earlyGrace) {
                        $conditions[] = 'early_leave';
                        $outStatus = $statuses->code('early_leave');
                    }
                }
            }
        }
        if (!$closed && (!$nonWorking || $ids)) {
            $review[] = 'Shift window is not closed; attendance is provisional';
        }
        if ($review) {
            $conditions[] = 'manual_review';
        }
        $resolved = $statuses->resolve($conditions);
        $review = array_values(array_unique(array_merge($review, $resolved['issues'])));
        $rawStatus = $review ? $statuses->code('manual_review') : $resolved['code'];
        $result = ['card_number' => (string) $map['card_number'], 'employee_nik' => $map['employee_nik'] ?? '', 'employee_name' => $map['employee_name'] ?? 'Unmapped card', 'attendance_date' => $slot['date'], 'shift_date' => $slot['date'], 'shift' => $shift['code'] ?? 'Unconfigured', 'shift_code' => $shift['code'] ?? null, 'shift_source' => $slot['resolved']['source'], 'scheduled_clock_in' => $slot['bounds'] ? $slot['bounds']['start']->format('c') : null, 'scheduled_clock_out' => $slot['bounds'] ? $slot['bounds']['end']->format('c') : null, 'actual_clock_in' => $clockIn['timestamp'] ?? null, 'actual_clock_out' => $clockOut['timestamp'] ?? null, 'late_minutes' => $late, 'early_leave_minutes' => $early, 'work_minutes' => $work, 'raw_log_count' => count($ids), 'raw_clock_in_status' => $inStatus, 'raw_clock_out_status' => $outStatus, 'raw_attendance_status' => $rawStatus, 'raw_conditions' => $resolved['codes'], 'final_attendance_status' => $rawStatus, 'final_status_source' => 'engine', 'validation_status' => $review ? 'manual_review' : ($punch ? 'incomplete' : 'complete'), 'mapping' => $map, 'shift_config' => $shift, 'calendar' => $calendar, 'events' => $events, 'source_log_ids' => $ids, 'source_classifications' => $classifications, 'ambiguous_source_ids' => $slot['potential_ids'], 'review_reasons' => $review, 'issues' => array_values(array_unique(array_merge($review, $punch)))];
        $result['input_hash'] = hash('sha256', json_encode([$result, $slot['resolved']['assignments'] ?? [], $closed], JSON_THROW_ON_ERROR));
        return $result;
    }
}
