<?php

declare (strict_types=1);
final class AttendanceEngineRepository
{
    private PDO $pdo;
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    private function q(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    private function t(string $name): string
    {
        return IntegrationMigrations::table($name);
    }
    public function installed(): bool
    {
        if (LegacyAttendanceRepository::active()) {
            return false;
        }
        $checksum = $this->q('SELECT checksum FROM ' . $this->t('schema_version') . ' WHERE version=:version', ['version' => 3])->fetchColumn();
        if ($checksum === false) {
            return false;
        }
        $file = ROOT . '/migrations/003_attendance_engine.' . (Database::driver() === 'mysql' ? 'mysql.sql' : 'sql');
        if (!hash_equals(hash_file('sha256', $file), $checksum)) {
            compat_throw(new RuntimeException('Attendance engine migration checksum differs.'));
        }
        return true;
    }
    public function requireInstalled(): void
    {
        if (!$this->installed()) {
            compat_throw(new RuntimeException('Run local migration 003 and seed before processing attendance.'));
        }
    }
    public function context(string $from, string $to, ?string $nik = null): array
    {
        $empty = ['assignments' => [], 'calendar' => [], 'statuses' => [], 'approved_requests' => [], 'period_locks' => [], 'attendance_records' => []];
        if (!$this->installed()) {
            return $empty;
        }
        $start = (new DateTimeImmutable($from))->modify('-2 days')->format('Y-m-d');
        $end = (new DateTimeImmutable($to))->modify('+2 days')->format('Y-m-d');
        $params = ['start' => $start, 'end' => $end];
        $scope = $nik === null ? '' : ' AND (employee_nik=:nik OR employee_nik IS NULL)';
        if ($nik !== null) {
            $params['nik'] = $nik;
        }
        $range = ' WHERE from_date<=:end AND to_date>=:start' . $scope;
        $dates = ' WHERE attendance_date>=:start AND attendance_date<=:end' . $scope;
        return ['assignments' => $this->q('SELECT * FROM ' . $this->t('attendance_shift_assignments') . $range . ' ORDER BY id', $params)->fetchAll(PDO::FETCH_ASSOC), 'calendar' => $this->q('SELECT * FROM ' . $this->t('attendance_work_calendar') . $range . ' ORDER BY id', $params)->fetchAll(PDO::FETCH_ASSOC), 'statuses' => $this->q('SELECT * FROM ' . $this->t('attendance_statuses') . ' ORDER BY code')->fetchAll(PDO::FETCH_ASSOC), 'approved_requests' => $this->q('SELECT * FROM ' . $this->t('attendance_requests') . $dates . ' ORDER BY id', $params)->fetchAll(PDO::FETCH_ASSOC), 'period_locks' => $this->q('SELECT * FROM ' . $this->t('attendance_period_locks') . $range . ' ORDER BY id', $params)->fetchAll(PDO::FETCH_ASSOC), 'attendance_records' => $this->q('SELECT id,input_hash,employee_nik,attendance_date,hr_manually_corrected,is_confirmed,approved_request_id,final_status_source FROM ' . $this->t('attendances') . $dates, $params)->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function employees(?string $nik = null): array
    {
        return $this->q('SELECT employee_nik FROM ' . $this->t('employee_mappings') . ($nik === null ? '' : ' WHERE employee_nik=:nik') . ' ORDER BY employee_nik', $nik === null ? [] : ['nik' => $nik])->fetchAll(PDO::FETCH_COLUMN);
    }
    public function recent(): array
    {
        $this->requireInstalled();
        return $this->q('SELECT * FROM ' . $this->t('attendances') . ' ORDER BY attendance_date DESC,employee_nik LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function find(string $nik, string $date, bool $lock = false): ?array
    {
        return $this->q('SELECT * FROM ' . $this->t('attendances') . ' WHERE employee_nik=:nik AND attendance_date=:date' . ($lock ? ' FOR UPDATE' : ''), ['nik' => $nik, 'date' => $date])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function seedStatuses(): int
    {
        $this->requireInstalled();
        $count = 0;
        foreach (jsonFile(ROOT . '/config/attendance-statuses.seed.json') as $row) {
            $sql = 'INSERT INTO ' . $this->t('attendance_statuses') . ' (code,condition_key,label,priority) VALUES (:code,:condition_key,:label,:priority)';
            $sql .= Database::driver() === 'mysql' ? ' ON DUPLICATE KEY UPDATE code=code' : ' ON CONFLICT DO NOTHING';
            $count += $this->q($sql, $row)->rowCount();
        }
        return $count;
    }
    private function protected(array $row): bool
    {
        return AttendanceProcessor::truth($row['hr_manually_corrected']) || AttendanceProcessor::truth($row['is_confirmed']) || !empty($row['approved_request_id']) || in_array($row['final_status_source'], ['hr_manual', 'approved_request', 'confirmed'], true);
    }
    private function locked(string $nik, string $date): bool
    {
        return (bool) $this->q('SELECT id FROM ' . $this->t('attendance_period_locks') . ' WHERE (employee_nik=:nik OR employee_nik IS NULL) AND from_date<=:start AND to_date>=:end AND locked=TRUE LIMIT 1', ['nik' => $nik, 'start' => $date, 'end' => $date])->fetchColumn();
    }
    public function exportable(array $group): bool
    {
        $row = $this->find($group['employee_nik'], $group['attendance_date']);
        if (!$row || $this->protected($row) || $this->locked($group['employee_nik'], $group['attendance_date']) || $row['final_status_source'] !== 'engine') {
            return false;
        }
        if ($this->q('SELECT id FROM ' . $this->t('attendance_requests') . ' WHERE employee_nik=:nik AND attendance_date=:date AND state=:state', ['nik' => $group['employee_nik'], 'date' => $group['attendance_date'], 'state' => 'approved'])->fetchColumn()) {
            return false;
        }
        return hash_equals($row['input_hash'], hash('sha256', ($group['batch_hash'] ?? $group['input_hash']) . json_encode(null, JSON_THROW_ON_ERROR)));
    }
    public function persist(array $record, bool $dryRun = false): string
    {
        $this->requireInstalled();
        $nik = $record['employee_nik'];
        $date = $record['attendance_date'];
        if ($nik === '') {
            return 'skipped_unmapped';
        }
        $outer = $this->pdo->inTransaction();
        $lockName = 'attendance-' . substr(hash('sha256', env('DB_DATABASE') . '|' . $nik . '|' . $date), 0, 40);
        $mysql = Database::driver() === 'mysql';
        if (!$dryRun) {
            if ($mysql) {
                if ((int) $this->q('SELECT GET_LOCK(:key,5)', ['key' => $lockName])->fetchColumn() !== 1) {
                    compat_throw(new RuntimeException('Attendance date is being processed by another worker.'));
                }
            }
            if ($outer) {
                $this->pdo->exec('SAVEPOINT attendance_record');
            } else {
                $this->pdo->beginTransaction();
            }
            if (!$mysql) {
                $this->q('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => $lockName]);
            }
        }
        $finish = function (bool $commit) use ($outer, $dryRun): void {
            if ($dryRun) {
                return;
            }
            if ($outer) {
                if (!$commit) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT attendance_record');
                }
                $this->pdo->exec('RELEASE SAVEPOINT attendance_record');
            } elseif ($this->pdo->inTransaction()) {
                if ($commit) {
                    $this->pdo->commit();
                } else {
                    $this->pdo->rollBack();
                }
            }
        };
        try {
            $existing = $this->find($nik, $date, !$dryRun);
            if ($this->locked($nik, $date)) {
                $finish(false);
                return 'skipped_locked';
            }
            if ($existing && $this->protected($existing)) {
                $finish(false);
                return 'skipped_protected';
            }
            $requests = $this->q('SELECT * FROM ' . $this->t('attendance_requests') . ' WHERE employee_nik=:nik AND attendance_date=:date AND state=:state', ['nik' => $nik, 'date' => $date, 'state' => 'approved'])->fetchAll(PDO::FETCH_ASSOC);
            if (count($requests) > 1) {
                compat_throw(new RuntimeException('Multiple approved requests for the same date require HR review.'));
            }
            $approved = $requests[0] ?? null;
            $hash = hash('sha256', $record['input_hash'] . json_encode($approved, JSON_THROW_ON_ERROR));
            if ($existing && hash_equals($existing['input_hash'], $hash)) {
                $finish(false);
                return 'skipped_unchanged';
            }
            $overrides = $approved ? $this->approvedValues(json_decode($approved['approved_values'], true, 512, JSON_THROW_ON_ERROR)) : [];
            $this->validateClockOrder($overrides + $record);
            $result = $existing ? 'updated' : 'created';
            if ($dryRun) {
                return 'would_' . $result;
            }
            $fields = ['employee_nik' => $nik, 'attendance_date' => $date];
            foreach (['shift_code', 'scheduled_clock_in', 'scheduled_clock_out', 'actual_clock_in', 'actual_clock_out', 'late_minutes', 'early_leave_minutes', 'work_minutes', 'raw_log_count', 'raw_clock_in_status', 'raw_clock_out_status', 'raw_attendance_status', 'final_attendance_status', 'final_status_source'] as $key) {
                $fields[$key] = $record[$key];
            }
            $fields['approved_request_id'] = $approved['id'] ?? null;
            if ($approved) {
                foreach ($overrides as $key => $value) {
                    $fields[$key] = $value;
                }
                $fields['final_status_source'] = 'approved_request';
            }
            foreach (['scheduled_clock_in', 'scheduled_clock_out', 'actual_clock_in', 'actual_clock_out'] as $key) {
                if ($fields[$key] !== null) {
                    $fields[$key] = $this->datetime($fields[$key]);
                }
            }
            $fields['raw_conditions'] = json_encode($record['raw_conditions'], JSON_THROW_ON_ERROR);
            $fields['input_hash'] = $hash;
            $fields['calculated_payload'] = json_encode($record, JSON_THROW_ON_ERROR);
            $expressions = [];
            foreach ($fields as $key => $value) {
                $expressions[$key] = in_array($key, ['raw_conditions', 'calculated_payload'], true) && !$mysql ? 'CAST(:' . $key . ' AS jsonb)' : ':' . $key;
            }
            if ($existing) {
                $sets = [];
                foreach ($expressions as $key => $value) {
                    $sets[] = $key . '=' . $value;
                }
                $this->q('UPDATE ' . $this->t('attendances') . ' SET ' . implode(',', $sets) . ',updated_at=CURRENT_TIMESTAMP WHERE id=:id', $fields + ['id' => $existing['id']]);
                $id = (int) $existing['id'];
                $this->q('DELETE FROM ' . $this->t('attendance_sources') . ' WHERE attendance_id=:id', ['id' => $id]);
            } else {
                $stmt = $this->q('INSERT INTO ' . $this->t('attendances') . ' (' . implode(',', array_keys($fields)) . ') VALUES (' . implode(',', $expressions) . ')' . ($mysql ? '' : ' RETURNING id'), $fields);
                $id = $mysql ? (int) $this->pdo->lastInsertId() : (int) $stmt->fetchColumn();
            }
            foreach ($record['source_classifications'] as $rawId => $classification) {
                $this->q('INSERT INTO ' . $this->t('attendance_sources') . ' (attendance_id,raw_event_id,classification) VALUES (:attendance,:raw,:classification)', ['attendance' => $id, 'raw' => $rawId, 'classification' => $classification]);
            }
            $finish(true);
            return $result;
        } catch (Throwable $error) {
            $finish(false);
            compat_throw($error);
        } finally {
            if (!$dryRun && $mysql) {
                $this->q('SELECT RELEASE_LOCK(:key)', ['key' => $lockName]);
            }
        }
    }
    private function datetime(string $time): string
    {
        $date = new DateTimeImmutable($time);
        return Database::driver() === 'mysql' ? $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u') : $date->format('c');
    }
    private function validateClockOrder(array $values): void
    {
        if (!empty($values['actual_clock_in']) && !empty($values['actual_clock_out']) && new DateTimeImmutable($values['actual_clock_out'], new DateTimeZone('UTC')) <= new DateTimeImmutable($values['actual_clock_in'], new DateTimeZone('UTC'))) {
            compat_throw(new InvalidArgumentException('Approved/manual clock-out must be after clock-in.'));
        }
    }
    private function configurationDates(string $from, string $to): void
    {
        AttendanceRepository::dateRange($from, $from);
        AttendanceRepository::dateRange($to, $to);
        if ($from > $to) {
            compat_throw(new InvalidArgumentException('Configuration end date must follow start date.'));
        }
    }
    private function approvedValues(array $values): array
    {
        $allowed = ['actual_clock_in', 'actual_clock_out', 'late_minutes', 'early_leave_minutes', 'work_minutes', 'final_attendance_status'];
        if (!$values || array_diff(array_keys($values), $allowed)) {
            compat_throw(new InvalidArgumentException('Approved/manual values contain unsupported or empty fields.'));
        }
        foreach ($values as $key => $value) {
            if (str_starts_with($key, 'actual_')) {
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)$/', $value)) {
                    compat_throw(new InvalidArgumentException('Approved timestamps require a full date, time and timezone.'));
                }
                $date = new DateTimeImmutable($value);
                if (compat_datetime_errors()) {
                    compat_throw(new InvalidArgumentException('Invalid approved timestamp.'));
                }
            } elseif (str_ends_with($key, '_minutes')) {
                if (!is_int($value) || $value < 0) {
                    compat_throw(new InvalidArgumentException('Approved minutes must be nonnegative integers.'));
                }
            } elseif (!is_string($value) || !$this->q('SELECT code FROM ' . $this->t('attendance_statuses') . ' WHERE code=:code', ['code' => $value])->fetchColumn()) {
                compat_throw(new InvalidArgumentException('Approved status must exist in the configured status master.'));
            }
        }
        $this->validateClockOrder($values);
        return $values;
    }
    public function logError(string $nik, string $date, Throwable $error, bool $dryRun): void
    {
        $message = $error instanceof PDOException ? 'Database error (SQLSTATE ' . $error->getCode() . ')' : substr($error->getMessage(), 0, 500);
        if (!$dryRun) {
            try {
                $this->q('INSERT INTO ' . $this->t('attendance_processing_errors') . ' (employee_nik,attendance_date,error_class,message) VALUES (:nik,:date,:class,:message)', ['nik' => $nik, 'date' => $date, 'class' => get_class($error), 'message' => $message]);
                return;
            } catch (Throwable $_ignored) {
            }
        }
        (new ReceiverAudit(ROOT . '/storage/attendance-processing.jsonl'))->append(['employee_nik' => $nik, 'attendance_date' => $date, 'error_class' => get_class($error), 'message' => $message, 'dry_run' => $dryRun]);
    }
    public function configure(string $kind, array $value): void
    {
        $this->requireInstalled();
        if ($kind === 'attendance-status') {
            foreach (['code', 'condition_key', 'label'] as $key) {
                if (!is_string($value[$key] ?? null) || $value[$key] === '') {
                    compat_throw(new InvalidArgumentException('Status code, condition key and label are required.'));
                }
            }
            if (!is_int($value['priority'] ?? null)) {
                compat_throw(new InvalidArgumentException('Status priority must be an integer.'));
            }
            $existing = $this->q('SELECT code FROM ' . $this->t('attendance_statuses') . ' WHERE condition_key=:key', ['key' => $value['condition_key']])->fetchColumn();
            if ($existing !== false && $existing !== $value['code']) {
                compat_throw(new InvalidArgumentException('Renaming an existing condition code requires a reviewed data migration.'));
            }
            $condition = $this->q('SELECT condition_key FROM ' . $this->t('attendance_statuses') . ' WHERE code=:code', ['code' => $value['code']])->fetchColumn();
            if ($condition !== false && $condition !== $value['condition_key']) {
                compat_throw(new InvalidArgumentException('Changing an existing status condition requires a reviewed data migration.'));
            }
            $params = array_intersect_key($value, array_flip(['code', 'condition_key', 'label', 'priority']));
            $sql = 'INSERT INTO ' . $this->t('attendance_statuses') . ' (code,condition_key,label,priority) VALUES (:code,:condition_key,:label,:priority)';
            $sql .= Database::driver() === 'mysql' ? ' ON DUPLICATE KEY UPDATE label=VALUES(label),priority=VALUES(priority)' : ' ON CONFLICT (code) DO UPDATE SET label=EXCLUDED.label,priority=EXCLUDED.priority';
            $this->q($sql, $params);
            return;
        }
        if ($kind === 'shift-policy') {
            $params = ['code' => $value['code'] ?? ''];
            foreach (['late_grace_minutes', 'early_leave_grace_minutes', 'duplicate_tap_seconds'] as $key) {
                if (!array_key_exists($key, $value) || !is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 3600) {
                    compat_throw(new InvalidArgumentException('Provide explicit nonnegative integer policy: ' . $key));
                }
                $params[$key] = $value[$key];
            }
            if (!is_bool($value['window_fallback_enabled'] ?? null) || !in_array($value['minute_rounding'] ?? null, ['floor', 'ceil'], true) || !in_array($value['grace_mode'] ?? null, ['threshold', 'deduct'], true)) {
                compat_throw(new InvalidArgumentException('Provide explicit fallback, minute rounding and grace mode.'));
            }
            $params += ['window_fallback_enabled' => $value['window_fallback_enabled'] ? 'true' : 'false', 'minute_rounding' => $value['minute_rounding'], 'grace_mode' => $value['grace_mode']];
            if (Database::driver() === 'mysql') {
                $params['window_fallback_enabled'] = $value['window_fallback_enabled'] ? 1 : 0;
            }
            if (!$this->q('SELECT code FROM ' . $this->t('shifts') . ' WHERE code=:code', ['code' => $params['code']])->fetchColumn()) {
                compat_throw(new InvalidArgumentException('Configure the existing shift master first.'));
            }
            $this->q('UPDATE ' . $this->t('shifts') . ' SET late_grace_minutes=:late_grace_minutes,early_leave_grace_minutes=:early_leave_grace_minutes,duplicate_tap_seconds=:duplicate_tap_seconds,window_fallback_enabled=:window_fallback_enabled,minute_rounding=:minute_rounding,grace_mode=:grace_mode WHERE code=:code', $params);
            return;
        }
        if ($kind === 'attendance-protect') {
            $nik = $value['employee_nik'] ?? '';
            $date = $value['attendance_date'] ?? '';
            AttendanceRepository::dateRange($date, $date);
            $outer = $this->pdo->inTransaction();
            if ($outer) {
                $this->pdo->exec('SAVEPOINT hr_adjustment');
            } else {
                $this->pdo->beginTransaction();
            }
            $finish = function (bool $commit) use ($outer): void {
                if ($outer) {
                    if (!$commit) {
                        $this->pdo->exec('ROLLBACK TO SAVEPOINT hr_adjustment');
                    }
                    $this->pdo->exec('RELEASE SAVEPOINT hr_adjustment');
                } elseif ($this->pdo->inTransaction()) {
                    if ($commit) {
                        $this->pdo->commit();
                    } else {
                        $this->pdo->rollBack();
                    }
                }
            };
            try {
                $row = $this->find($nik, $date, true);
                if (!$row) {
                    compat_throw(new RuntimeException('Process the attendance date before applying a manual correction or confirmation.'));
                }
                if ($this->locked($nik, $date) || AttendanceProcessor::truth($row['is_confirmed'])) {
                    compat_throw(new RuntimeException('Locked or confirmed attendance cannot be corrected by this import.'));
                }
                $overrides = $this->approvedValues($value['values'] ?? []);
                $sets = [];
                $params = ['id' => $row['id'], 'reason' => $value['reason'] ?? 'HR correction'];
                $this->validateClockOrder($overrides + $row);
                foreach ($overrides as $key => $item) {
                    $sets[] = $key . '=:' . $key;
                    $params[$key] = str_starts_with($key, 'actual_') ? $this->datetime($item) : $item;
                }
                $this->q('UPDATE ' . $this->t('attendances') . ' SET ' . implode(',', $sets) . ",hr_manually_corrected=TRUE,final_status_source='hr_manual',protection_reason=:reason,updated_at=CURRENT_TIMESTAMP WHERE id=:id", $params);
                $finish(true);
                return;
            } catch (Throwable $error) {
                $finish(false);
                compat_throw($error);
            }
        }
        if ($kind === 'attendance-request') {
            $this->approvedValues($value['approved_values'] ?? []);
            $params = [];
            foreach (['reference', 'employee_nik', 'attendance_date', 'state'] as $key) {
                $params[$key] = $value[$key] ?? '';
            }
            AttendanceRepository::dateRange($params['attendance_date'], $params['attendance_date']);
            if (!in_array($params['state'], ['pending', 'approved', 'rejected'], true) || $params['reference'] === '' || $params['employee_nik'] === '') {
                compat_throw(new InvalidArgumentException('Explicit request reference, employee, date and state are required.'));
            }
            $params['approved_values'] = json_encode($value['approved_values'], JSON_THROW_ON_ERROR);
            $json = Database::driver() === 'mysql' ? ':approved_values' : 'CAST(:approved_values AS jsonb)';
            $this->q('INSERT INTO ' . $this->t('attendance_requests') . ' (reference,employee_nik,attendance_date,state,approved_values) VALUES (:reference,:employee_nik,:attendance_date,:state,' . $json . ')', $params);
            return;
        }
        $definitions = ['shift-assignment' => ['attendance_shift_assignments', 'assignment_key', ['employee_nik', 'shift_code', 'from_date', 'to_date', 'priority', 'source']], 'work-calendar' => ['attendance_work_calendar', 'rule_key', ['employee_nik', 'from_date', 'to_date', 'day_type', 'priority', 'note']], 'attendance-period-lock' => ['attendance_period_locks', 'lock_key', ['employee_nik', 'from_date', 'to_date', 'locked', 'reason']]];
        if (!isset($definitions[$kind])) {
            compat_throw(new InvalidArgumentException('Unknown attendance configuration kind.'));
        }
        [$table, $hashField, $fields] = $definitions[$kind];
        $params = [];
        foreach ($fields as $key) {
            $params[$key] = $value[$key] ?? null;
        }
        $this->configurationDates($params['from_date'], $params['to_date']);
        if ($kind !== 'attendance-period-lock' && !is_int($params['priority'])) {
            compat_throw(new InvalidArgumentException('An explicit integer priority is required.'));
        }
        if ($kind === 'shift-assignment' && (!$params['employee_nik'] || !$params['source'])) {
            compat_throw(new InvalidArgumentException('Roster employee and source are required.'));
        }
        if ($kind === 'work-calendar' && !in_array($params['day_type'], ['working', 'day_off', 'holiday'], true)) {
            compat_throw(new InvalidArgumentException('Invalid calendar day type.'));
        }
        if ($kind === 'attendance-period-lock') {
            if (!is_bool($params['locked']) || !is_string($params['reason']) || $params['reason'] === '') {
                compat_throw(new InvalidArgumentException('An explicit lock flag and reason are required.'));
            }
            $params['locked'] = Database::driver() === 'mysql' ? $params['locked'] ? 1 : 0 : ($params['locked'] ? 'true' : 'false');
        }
        $identity = $params;
        unset($identity['shift_code'], $identity['day_type'], $identity['locked'], $identity['note'], $identity['reason']);
        $params[$hashField] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $sql = 'INSERT INTO ' . $this->t($table) . ' (' . implode(',', array_keys($params)) . ') VALUES (:' . implode(',:', array_keys($params)) . ')';
        $updates = [];
        foreach ($fields as $field) {
            $updates[] = $field . '=' . (Database::driver() === 'mysql' ? 'VALUES(' . $field . ')' : 'EXCLUDED.' . $field);
        }
        $sql .= Database::driver() === 'mysql' ? ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates) : ' ON CONFLICT (' . $hashField . ') DO UPDATE SET ' . implode(',', $updates);
        $this->q($sql, $params);
    }
}
