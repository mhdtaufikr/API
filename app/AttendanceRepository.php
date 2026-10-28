<?php

declare (strict_types=1);
final class AttendanceRepository implements ReceiverEventStore
{
    private string $purpose;
    private ?PDO $pdo;
    public function __construct(?PDO $pdo = null, string $purpose = 'reader')
    {
        $this->purpose = $purpose;
        $this->pdo = $pdo;
    }
    public function db(): PDO
    {
        if (env('INTEGRATION_STORAGE') === 'legacy_app') {
            compat_throw(new RuntimeException('Archived integration repository disabled. Use the four-table legacy adapter.'));
        }
        if ($this->pdo === null) {
            if (PHP_VERSION_ID < 70400) {
                compat_throw(new RuntimeException('Machine integration requires PHP 7.4+.'));
            }
            $this->pdo = Database::open($this->purpose);
        }
        return $this->pdo;
    }
    private function query(string $sql, array $params = []): PDOStatement
    {
        if (Database::driver() === 'mysql') {
            $database = env('DB_DATABASE');
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $database)) {
                compat_throw(new RuntimeException('Invalid integration database name.'));
            }
            $sql = str_replace('mkm_attendance.', '`' . $database . '`.', $sql);
            $sql = preg_replace('/CAST\((:[a-z_]+) AS (?:jsonb|inet|boolean)\)/', '$1', $sql);
            $sql = preg_replace('/ON CONFLICT \([a-z_]+\) DO UPDATE SET/', 'ON DUPLICATE KEY UPDATE', $sql);
            $sql = preg_replace('/EXCLUDED\.([a-z_]+)/', 'VALUES($1)', $sql);
            foreach (['event_datetime', 'start', 'end'] as $key) {
                if (isset($params[$key])) {
                    $params[$key] = (new DateTimeImmutable($params[$key]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                }
            }
            if (isset($params['verified'])) {
                $params['verified'] = $params['verified'] === 'true' ? 1 : 0;
            }
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    private function normalize(array $row): array
    {
        if (Database::driver() === 'mysql' && isset($row['event_datetime'])) {
            $row['event_datetime'] = (new DateTimeImmutable($row['event_datetime'], new DateTimeZone('UTC')))->format('c');
        }
        return $row;
    }
    public function assertInstalled(): void
    {
        $row = $this->query('SELECT checksum FROM mkm_attendance.schema_version WHERE version = :version', ['version' => 1])->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals(hash_file('sha256', Database::migrationFile()), $row['checksum'])) {
            compat_throw(new RuntimeException('Integration schema is not installed or its migration checksum differs.'));
        }
    }
    public function store(array $event, array $payload, string $sourceIp): array
    {
        $this->assertInstalled();
        $sql = 'INSERT INTO mkm_attendance.attendance_raw_events (card_number,terminal_id,direction_code,event_datetime,raw_message,protocol_profile,request_payload,source_ip,event_hash) VALUES (:card_number,:terminal_id,:direction_code,:event_datetime,:raw_message,:protocol_profile,CAST(:payload AS jsonb),CAST(:source_ip AS inet),:event_hash) ON CONFLICT (event_hash) DO NOTHING RETURNING id';
        $params = $event + ['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'source_ip' => $sourceIp];
        if (Database::driver() === 'mysql') {
            $sql = explode(' ON CONFLICT', $sql)[0];
            try {
                $this->query($sql, $params);
                return ['id' => (int) $this->db()->lastInsertId(), 'duplicate' => false];
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1062) {
                    compat_throw($error);
                }
            }
            $id = false;
        } else {
            $id = $this->query($sql, $params)->fetchColumn();
        }
        if ($id !== false) {
            return ['id' => (int) $id, 'duplicate' => false];
        }
        $existing = $this->findByHash($event['event_hash']);
        if (!$existing) {
            compat_throw(new RuntimeException('Duplicate event record could not be retrieved.'));
        }
        return ['id' => (int) $existing['id'], 'duplicate' => true];
    }
    public function findByHash(string $hash): ?array
    {
        $row = $this->query('SELECT * FROM mkm_attendance.attendance_raw_events WHERE event_hash = :hash', ['hash' => $hash])->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalize($row) : null;
    }
    public function recent(): array
    {
        return array_map([$this, 'normalize'], $this->query('SELECT * FROM mkm_attendance.attendance_raw_events ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC));
    }
    public function count(): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM mkm_attendance.attendance_raw_events')->fetchColumn();
    }
    public function snapshot(string $from, string $to, ?string $nik = null): array
    {
        self::dateRange($from, $to);
        // Include the complete following day for overnight shifts; the processor filters shift dates.
        $zone = new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'));
        $start = (new DateTimeImmutable($from, $zone))->modify('-1 day')->format('c');
        $end = (new DateTimeImmutable($to, $zone))->modify('+3 days')->format('c');
        $scope = $nik === null ? '' : ' AND card_number IN (SELECT card_number FROM mkm_attendance.employee_mappings WHERE employee_nik=:nik)';
        $params = ['start' => $start, 'end' => $end];
        if ($nik !== null) {
            $params['nik'] = $nik;
        }
        $rows = $this->query('SELECT * FROM mkm_attendance.attendance_raw_events WHERE event_datetime >= :start AND event_datetime < :end' . $scope . ' ORDER BY event_datetime,id LIMIT 5001', $params)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 5000) {
            compat_throw(new RuntimeException('More than 5000 transactions. Narrow the attendance date range.'));
        }
        $snapshot = ['rows' => array_map([$this, 'normalize'], $rows), 'mappings' => $this->query('SELECT * FROM mkm_attendance.employee_mappings' . ($nik === null ? '' : ' WHERE employee_nik=:nik') . ' ORDER BY card_number', $nik === null ? [] : ['nik' => $nik])->fetchAll(PDO::FETCH_ASSOC), 'shifts' => $this->query('SELECT * FROM mkm_attendance.shifts ORDER BY code')->fetchAll(PDO::FETCH_ASSOC), 'terminals' => $this->query('SELECT * FROM mkm_attendance.terminals ORDER BY terminal_id')->fetchAll(PDO::FETCH_ASSOC)];
        return array_merge($snapshot, (new AttendanceEngineRepository($this->db()))->context($from, $to, $nik));
    }
    public static function dateRange(string $from, string $to): void
    {
        foreach ([$from, $to] as $value) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (!$d || $d->format('Y-m-d') !== $value) {
                compat_throw(new InvalidArgumentException('Invalid attendance date range.'));
            }
        }
        if ($from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 31) {
            compat_throw(new InvalidArgumentException('Select at most 31 days.'));
        }
    }
    public function queue(): array
    {
        return $this->query('SELECT id,employee_nik,shift_date,status,response,created_at FROM mkm_attendance.integration_queue ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }
    public function queueCounts(): array
    {
        return $this->query('SELECT status,COUNT(*) AS total FROM mkm_attendance.integration_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public function queueEntry(int $id): array
    {
        $row = $this->query('SELECT * FROM mkm_attendance.integration_queue WHERE id = :id', ['id' => $id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            compat_throw(new RuntimeException('Queue entry not found.'));
        }
        $row['snapshot'] = json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }
    public function enqueue(array $group): bool
    {
        if ($group['status'] !== 'ready_for_review' || env('ATTENDANCE_TEST_NIK') === '' || $group['employee_nik'] !== env('ATTENDANCE_TEST_NIK')) {
            return false;
        }
        $engine = new AttendanceEngineRepository($this->db());
        if ($engine->installed() && !$engine->exportable($group)) {
            return false;
        }
        $db = $this->db();
        $outer = $db->inTransaction();
        if ($outer) {
            $db->exec('SAVEPOINT mkm_enqueue');
        } else {
            $db->beginTransaction();
        }
        $complete = function (bool $commit) use ($db, $outer): void {
            if ($outer) {
                if (!$commit) {
                    $db->exec('ROLLBACK TO SAVEPOINT mkm_enqueue');
                }
                $db->exec('RELEASE SAVEPOINT mkm_enqueue');
            } elseif ($db->inTransaction()) {
                if ($commit) {
                    $db->commit();
                } else {
                    $db->rollBack();
                }
            }
        };
        try {
            $sql = 'INSERT INTO mkm_attendance.integration_queue (batch_hash,card_number,employee_nik,shift_date,snapshot) VALUES (:hash,:card,:nik,:date,CAST(:snapshot AS jsonb))';
            $params = ['hash' => $group['batch_hash'], 'card' => $group['card_number'], 'nik' => $group['employee_nik'], 'date' => $group['shift_date'], 'snapshot' => json_encode($group, JSON_THROW_ON_ERROR)];
            if (Database::driver() === 'mysql') {
                $this->query($sql, $params);
                $id = $db->lastInsertId();
            } else {
                $id = $this->query($sql . ' ON CONFLICT (batch_hash) DO NOTHING RETURNING id', $params)->fetchColumn();
            }
            if ($id === false) {
                $complete(false);
                return false;
            }
            foreach ($group['events'] as $event) {
                $this->query('INSERT INTO mkm_attendance.queue_events (queue_id,raw_event_id) VALUES (:queue,:event)', ['queue' => $id, 'event' => $event['id']]);
            }
            $complete(true);
            return true;
        } catch (PDOException $error) {
            $complete(false);
            if ($error->getCode() === '23505' || ($error->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            compat_throw($error);
        } catch (Throwable $error) {
            $complete(false);
            compat_throw($error);
        }
    }
    public function reserve(int $id): void
    {
        $stmt = $this->query("UPDATE mkm_attendance.integration_queue SET status = 'reserved',updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'pending_review'", ['id' => $id]);
        if ($stmt->rowCount() !== 1) {
            compat_throw(new RuntimeException('Queue entry already attempted or reserved; reconcile before retry.'));
        }
    }
    public function schedulerConfiguration(): array
    {
        $nik = env('ATTENDANCE_TEST_NIK');
        if ($nik === '') {
            compat_throw(new RuntimeException('Configure the single test NIK before scheduler approval.'));
        }
        $mapping = $this->query('SELECT * FROM mkm_attendance.employee_mappings WHERE employee_nik=:nik', ['nik' => $nik])->fetch(PDO::FETCH_ASSOC);
        if (!$mapping || !AttendanceProcessor::truth($mapping['verified'])) {
            compat_throw(new RuntimeException('Scheduler requires a verified employee/card mapping.'));
        }
        foreach (['talenta_employee_id', 'talenta_user_id', 'badge_number', 'shift_code'] as $field) {
            if (empty($mapping[$field])) {
                compat_throw(new RuntimeException('Complete the scheduler employee mapping: ' . $field));
            }
        }
        if ($mapping['talenta_user_id'] !== env('TALENTA_USER_ID')) {
            compat_throw(new RuntimeException('Mapped and configured Talenta User IDs differ.'));
        }
        return ['mapping' => $mapping, 'shifts' => $this->query('SELECT * FROM mkm_attendance.shifts ORDER BY code')->fetchAll(PDO::FETCH_ASSOC), 'terminals' => $this->query('SELECT * FROM mkm_attendance.terminals ORDER BY terminal_id')->fetchAll(PDO::FETCH_ASSOC), 'assignments' => $this->query('SELECT * FROM mkm_attendance.attendance_shift_assignments WHERE employee_nik=:nik ORDER BY id', ['nik' => $nik])->fetchAll(PDO::FETCH_ASSOC), 'calendar' => $this->query('SELECT * FROM mkm_attendance.attendance_work_calendar WHERE employee_nik=:nik OR employee_nik IS NULL ORDER BY id', ['nik' => $nik])->fetchAll(PDO::FETCH_ASSOC), 'statuses' => $this->query('SELECT * FROM mkm_attendance.attendance_statuses ORDER BY code')->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function claimedEvents(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $params = [];
        $holders = [];
        foreach (array_values(array_unique($ids)) as $i => $id) {
            $key = 'id_' . $i;
            $params[$key] = $id;
            $holders[] = ':' . $key;
        }
        return array_map('intval', $this->query('SELECT raw_event_id FROM mkm_attendance.queue_events WHERE raw_event_id IN (' . implode(',', $holders) . ')', $params)->fetchAll(PDO::FETCH_COLUMN));
    }
    public function enqueueScheduled(array $record, array $events, string $profile): ?int
    {
        if (!enabled('TALENTA_SCHEDULER_ENABLED') || !$events || $record['employee_nik'] !== env('ATTENDANCE_TEST_NIK')) {
            compat_throw(new RuntimeException('Scheduled queue scope is disabled or invalid.'));
        }
        $db = $this->db();
        $outer = $db->inTransaction();
        if ($outer) {
            $db->exec('SAVEPOINT scheduled_queue');
        } else {
            $db->beginTransaction();
        }
        $complete = function (bool $commit) use ($db, $outer): void {
            if ($outer) {
                if (!$commit) {
                    $db->exec('ROLLBACK TO SAVEPOINT scheduled_queue');
                }
                $db->exec('RELEASE SAVEPOINT scheduled_queue');
            } elseif ($db->inTransaction()) {
                if ($commit) {
                    $db->commit();
                } else {
                    $db->rollBack();
                }
            }
        };
        try {
            $snapshot = ['mode' => 'scheduled_events', 'approved_profile' => $profile, 'attendance_date' => $record['attendance_date'], 'source_hash' => $record['input_hash'], 'events' => $events];
            $hash = hash('sha256', json_encode([env('MEKARI_COMPANY_ID'), env('TALENTA_USER_ID'), array_column($events, 'id'), array_map([Mekari::class, 'eventKey'], $events)], JSON_THROW_ON_ERROR));
            $sql = 'INSERT INTO mkm_attendance.integration_queue (batch_hash,card_number,employee_nik,shift_date,snapshot) VALUES (:hash,:card,:nik,:date,CAST(:snapshot AS jsonb))';
            $params = ['hash' => $hash, 'card' => $record['card_number'], 'nik' => $record['employee_nik'], 'date' => $record['attendance_date'], 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)];
            if (Database::driver() === 'mysql') {
                $this->query($sql, $params);
                $id = (int) $db->lastInsertId();
            } else {
                $id = (int) $this->query($sql . ' RETURNING id', $params)->fetchColumn();
            }
            foreach ($events as $event) {
                $this->query('INSERT INTO mkm_attendance.queue_events (queue_id,raw_event_id) VALUES (:queue,:event)', ['queue' => $id, 'event' => $event['id']]);
            }
            $complete(true);
            return $id;
        } catch (PDOException $error) {
            $complete(false);
            if ($error->getCode() === '23505' || ($error->errorInfo[1] ?? null) === 1062) {
                return null;
            }
            compat_throw($error);
        } catch (Throwable $error) {
            $complete(false);
            compat_throw($error);
        }
    }
    public function finish(int $id, array $response): void
    {
        $this->query('UPDATE mkm_attendance.integration_queue SET status = :status,response = CAST(:response AS jsonb),updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :reserved', ['id' => $id, 'status' => $response['status'], 'response' => json_encode($response, JSON_THROW_ON_ERROR), 'reserved' => 'reserved']);
    }
    public function reconcile(int $id, bool $success, string $reference): void
    {
        if (trim($reference) === '' || strlen($reference) > 500) {
            compat_throw(new InvalidArgumentException('Provide a non-secret Talenta verification reference (maximum 500 characters).'));
        }
        $merge = Database::driver() === 'mysql' ? "JSON_MERGE_PATCH(COALESCE(response,JSON_OBJECT()),:evidence)" : "COALESCE(response,'{}'::jsonb) || CAST(:evidence AS jsonb)";
        $stmt = $this->query("UPDATE mkm_attendance.integration_queue SET status=:status,response={$merge},updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status IN ('reserved','submitted_pending_reconciliation','unknown','rejected')", ['status' => $success ? 'confirmed' : 'confirmed_failed', 'evidence' => json_encode(['verified_at' => gmdate('c'), 'verification_reference' => $reference], JSON_THROW_ON_ERROR), 'id' => $id]);
        if ($stmt->rowCount() !== 1) {
            compat_throw(new RuntimeException('Only previously attempted queue entries can be reconciled.'));
        }
    }
    public function configure(string $kind, array $value): void
    {
        $this->assertInstalled();
        if ($kind === 'shift') {
            foreach (['code', 'timezone', 'start_time', 'end_time', 'weekdays', 'in_before_minutes', 'in_after_minutes', 'out_before_minutes', 'out_after_minutes'] as $key) {
                if (!array_key_exists($key, $value)) {
                    compat_throw(new InvalidArgumentException('Missing shift field: ' . $key));
                }
            }
            new DateTimeZone($value['timezone']);
            foreach (['start_time', 'end_time'] as $key) {
                if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value[$key])) {
                    compat_throw(new InvalidArgumentException('Shift times must use HH:MM.'));
                }
            }
            if ($value['start_time'] === $value['end_time']) {
                compat_throw(new InvalidArgumentException('A shift cannot have equal start and end times.'));
            }
            if (!is_array($value['weekdays']) || !$value['weekdays'] || array_diff($value['weekdays'], [1, 2, 3, 4, 5, 6, 7])) {
                compat_throw(new InvalidArgumentException('Use ISO weekdays 1..7.'));
            }
            foreach (['in_before_minutes', 'in_after_minutes', 'out_before_minutes', 'out_after_minutes'] as $key) {
                if (!is_int($value[$key]) || $value[$key] < 0 || $value[$key] > 240) {
                    compat_throw(new InvalidArgumentException('Shift tolerances must be integers 0..240.'));
                }
            }
            $value['weekdays'] = json_encode($value['weekdays'], JSON_THROW_ON_ERROR);
            $this->query('INSERT INTO mkm_attendance.shifts (code,timezone,start_time,end_time,weekdays,in_before_minutes,in_after_minutes,out_before_minutes,out_after_minutes) VALUES (:code,:timezone,:start_time,:end_time,CAST(:weekdays AS jsonb),:in_before_minutes,:in_after_minutes,:out_before_minutes,:out_after_minutes) ON CONFLICT (code) DO UPDATE SET timezone=EXCLUDED.timezone,start_time=EXCLUDED.start_time,end_time=EXCLUDED.end_time,weekdays=EXCLUDED.weekdays,in_before_minutes=EXCLUDED.in_before_minutes,in_after_minutes=EXCLUDED.in_after_minutes,out_before_minutes=EXCLUDED.out_before_minutes,out_after_minutes=EXCLUDED.out_after_minutes', array_intersect_key($value, array_flip(['code', 'timezone', 'start_time', 'end_time', 'weekdays', 'in_before_minutes', 'in_after_minutes', 'out_before_minutes', 'out_after_minutes'])));
        } elseif ($kind === 'terminal') {
            if (!preg_match('/\A[0-9]{3}\z/', $value['terminal_id'] ?? '') || !is_array($value['direction_map'] ?? null) || array_diff(array_values($value['direction_map']), ['IN', 'OUT'])) {
                compat_throw(new InvalidArgumentException('Configure an observed terminal and documented IN/OUT direction values.'));
            }
            foreach (array_keys($value['direction_map']) as $key) {
                if (!preg_match('/\A[0-9]{2}\z/', (string) $key)) {
                    compat_throw(new InvalidArgumentException('Direction codes must have two digits.'));
                }
            }
            $this->query('INSERT INTO mkm_attendance.terminals (terminal_id,direction_map) VALUES (:id,CAST(:map AS jsonb)) ON CONFLICT (terminal_id) DO UPDATE SET direction_map=EXCLUDED.direction_map', ['id' => $value['terminal_id'], 'map' => json_encode((object) $value['direction_map'], JSON_THROW_ON_ERROR)]);
        } elseif ($kind === 'employee-map') {
            foreach (['card_number', 'employee_nik', 'employee_name'] as $key) {
                if (!is_string($value[$key] ?? null) || trim($value[$key]) === '') {
                    compat_throw(new InvalidArgumentException('Explicit card, NIK and employee name are required.'));
                }
            }
            $params = [];
            foreach (['card_number', 'employee_nik', 'employee_name', 'talenta_employee_id', 'talenta_user_id', 'badge_number', 'shift_code'] as $key) {
                $params[$key] = $value[$key] ?? null;
            }
            if (($value['verified'] ?? false) === true) {
                foreach (['talenta_employee_id', 'talenta_user_id', 'badge_number', 'shift_code'] as $key) {
                    if (!is_string($params[$key]) || $params[$key] === '') {
                        compat_throw(new InvalidArgumentException('A verified mapping must explicitly define ' . $key));
                    }
                }
            }
            $params['verified'] = ($value['verified'] ?? false) === true ? 'true' : 'false';
            $this->query('INSERT INTO mkm_attendance.employee_mappings (card_number,employee_nik,employee_name,talenta_employee_id,talenta_user_id,badge_number,shift_code,verified) VALUES (:card_number,:employee_nik,:employee_name,:talenta_employee_id,:talenta_user_id,:badge_number,:shift_code,CAST(:verified AS boolean)) ON CONFLICT (card_number) DO UPDATE SET employee_nik=EXCLUDED.employee_nik,employee_name=EXCLUDED.employee_name,talenta_employee_id=EXCLUDED.talenta_employee_id,talenta_user_id=EXCLUDED.talenta_user_id,badge_number=EXCLUDED.badge_number,shift_code=EXCLUDED.shift_code,verified=EXCLUDED.verified,updated_at=CURRENT_TIMESTAMP', $params);
        } else {
            compat_throw(new InvalidArgumentException('Unknown configuration kind.'));
        }
    }
}
