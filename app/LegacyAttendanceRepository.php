<?php

declare (strict_types=1);
/** Legacy source adapter: no generated raw table, no synthetic persistent row ID. */
final class LegacyAttendanceRepository implements ReceiverEventStore
{
    private PDO $pdo;
    public const FIELDS = ['FdDate', 'FcTime', 'FcCardNo', 'FcDirection', 'FcTermNo', 'FlFlag'];
    private const SCHEDULE_ORDER = ['FdDate', 'FcTime', 'FcCardNo', 'FcTermNo', 'FcDirection'];
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    public static function active(): bool
    {
        return in_array(env('INTEGRATION_STORAGE'), ['legacy_readonly', 'legacy_local', 'legacy_app'], true);
    }
    private function table(): string
    {
        $schema = Database::driver() === 'mysql' ? env('DB_DATABASE') : env('LEGACY_SCHEMA', 'public');
        return Database::quote($schema) . '.' . Database::quote('tbl_rawdata');
    }
    public function inspect(): array
    {
        $schema = Database::driver() === 'mysql' ? env('DB_DATABASE') : env('LEGACY_SCHEMA', 'public');
        $columns = array_values(array_filter(Database::columns($this->pdo), fn($c) => $c['table_schema'] === $schema && $c['table_name'] === 'tbl_rawdata'));
        if (array_diff(self::FIELDS, array_column($columns, 'column_name'))) {
            compat_throw(new RuntimeException('Legacy schema must contain exact tbl_rawdata column names/capitalization. No migration was run.'));
        }
        return $columns;
    }
    public function rows(string $from, string $to, ?string $card = null): array
    {
        AttendanceRepository::dateRange($from, $to);
        $this->inspect();
        if ($card !== null && ($card === '' || strlen($card) > 50)) {
            compat_throw(new InvalidArgumentException('Invalid card filter.'));
        }
        $q = [Database::class, 'quote'];
        $fields = implode(',', array_map($q, self::FIELDS));
        $sql = 'SELECT ' . $fields . ' FROM ' . $this->table() . ' WHERE ' . $q('FdDate') . ' >= :start AND ' . $q('FdDate') . ' <= :end';
        $params = ['start' => $from, 'end' => $to];
        if ($card !== null) {
            $sql .= ' AND ' . $q('FcCardNo') . ' = :card';
            $params['card'] = $card;
        }
        $sql .= ' ORDER BY ' . $q('FdDate') . ' DESC,' . $q('FcTime') . ' DESC,' . $q('FcCardNo') . ',' . $q('FcTermNo') . ',' . $q('FcDirection') . ' LIMIT 1001';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1000) {
            compat_throw(new RuntimeException('More than 1000 raw rows; narrow dates or card.'));
        }
        $counts = [];
        foreach ($rows as $row) {
            $hash = self::fingerprint($row);
            $counts[$hash] = ($counts[$hash] ?? 0) + 1;
        }
        foreach ($rows as &$row) {
            $row['value_group_hash'] = self::fingerprint($row);
            $row['identical_rows'] = $counts[$row['value_group_hash']];
            $row['identity_ambiguous'] = $row['identical_rows'] > 1;
        }
        return $rows;
    }

    /**
     * Deterministic scheduler scopes use keyset cursors. OFFSET is deliberately
     * avoided because successful batches change FlFlag while a run is active.
     */
    public function scheduledScopes(string $from, string $to, int $employeeBatchSize, int $rowBatchSize): Generator
    {
        AttendanceRepository::dateRange($from, $to);
        if ($employeeBatchSize < 1 || $employeeBatchSize > 100 || $rowBatchSize < 1 || $rowBatchSize > 1000) {
            compat_throw(new InvalidArgumentException('Invalid scheduled employee/row batch size.'));
        }
        $this->inspect();
        $afterCard = null;
        while (true) {
            $cards = $this->pendingCardPage($from, $to, $afterCard, $employeeBatchSize);
            if (!$cards) {
                return;
            }
            $cursor = null;
            while (true) {
                $page = $this->scheduledRowsPage($from, $to, $cards, $cursor, $rowBatchSize);
                if (!$page['rows']) {
                    break;
                }
                yield [
                    'from' => $from,
                    'to' => $to,
                    'cards' => $cards,
                    'cursor' => $cursor,
                    'row_limit' => $rowBatchSize,
                    'record_count' => $page['record_count'],
                ];
                if (!$page['has_more']) {
                    break;
                }
                $cursor = $page['next_cursor'];
            }
            $afterCard = (string) end($cards);
            if (count($cards) < $employeeBatchSize) {
                return;
            }
        }
    }

    /** @return string[] */
    private function pendingCardPage(string $from, string $to, ?string $afterCard, int $limit): array
    {
        $q = [Database::class, 'quote'];
        $card = $q('FcCardNo');
        $cardKey = Database::driver() === 'mysql'
            ? 'COALESCE(CAST(' . $card . ' AS CHAR),\'\')'
            : 'COALESCE(' . $card . '::text,\'\')';
        $sql = 'SELECT DISTINCT ' . $cardKey . ' AS card_key FROM ' . $this->table()
            . ' WHERE ' . $q('FdDate') . ' >= :start AND ' . $q('FdDate') . ' <= :end'
            . ' AND ' . $q('FlFlag') . '=0';
        $params = ['start' => $from, 'end' => $to];
        if ($afterCard !== null) {
            $sql .= ' AND ' . $cardKey . ' > :after_card';
            $params['after_card'] = $afterCard;
        }
        $sql .= ' ORDER BY card_key ASC LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Returns grouped immutable tuples. identical_rows > 1 is retained as an
     * ambiguity and never submitted or flagged automatically.
     */
    public function scheduledRowsPage(string $from, string $to, array $cards, ?array $cursor, int $limit): array
    {
        AttendanceRepository::dateRange($from, $to);
        if (!$cards || count($cards) > 100 || $limit < 1 || $limit > 1000) {
            compat_throw(new InvalidArgumentException('Invalid scheduled row page.'));
        }
        foreach ($cards as $card) {
            if (!is_string($card) || strlen($card) > 50) {
                compat_throw(new InvalidArgumentException('Invalid scheduled card value.'));
            }
        }
        if ($cursor !== null && count($cursor) !== count(self::SCHEDULE_ORDER)) {
            compat_throw(new InvalidArgumentException('Invalid scheduled cursor.'));
        }

        $q = [Database::class, 'quote'];
        $fields = implode(',', array_map($q, self::FIELDS));
        $holders = [];
        $params = ['start' => $from, 'end' => $to];
        foreach (array_values($cards) as $index => $card) {
            $holder = 'card_' . $index;
            $holders[] = ':' . $holder;
            $params[$holder] = $card;
        }
        $orderExpressions = array_map(function (string $field) use ($q): string {
            $quoted = $q($field);
            return Database::driver() === 'mysql'
                ? 'COALESCE(CAST(' . $quoted . ' AS CHAR),\'\')'
                : 'COALESCE(' . $quoted . '::text,\'\')';
        }, self::SCHEDULE_ORDER);

        $cardExpression = $orderExpressions[2];
        $sql = 'SELECT ' . $fields . ',COUNT(*) AS identical_rows FROM ' . $this->table()
            . ' WHERE ' . $q('FdDate') . ' >= :start AND ' . $q('FdDate') . ' <= :end'
            . ' AND ' . $q('FlFlag') . '=0 AND ' . $cardExpression . ' IN (' . implode(',', $holders) . ')';
        if ($cursor !== null) {
            $cursorHolders = [];
            foreach (array_values($cursor) as $index => $value) {
                $holder = 'cursor_' . $index;
                $cursorHolders[] = ':' . $holder;
                $params[$holder] = (string) $value;
            }
            $sql .= ' AND (' . implode(',', $orderExpressions) . ') > (' . implode(',', $cursorHolders) . ')';
        }
        $sql .= ' GROUP BY ' . $fields
            . ' ORDER BY ' . implode(' ASC,', $orderExpressions) . ' ASC LIMIT ' . ($limit + 1);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $recordCount = 0;
        foreach ($rows as &$row) {
            $row['identical_rows'] = (int) $row['identical_rows'];
            $row['identity_ambiguous'] = $row['identical_rows'] > 1;
            $row['value_group_hash'] = self::fingerprint($row);
            $recordCount += $row['identical_rows'];
        }
        unset($row);
        $nextCursor = null;
        if ($rows) {
            $last = $rows[array_key_last($rows)];
            $nextCursor = array_map(fn(string $field): string => (string) ($last[$field] ?? ''), self::SCHEDULE_ORDER);
        }
        return [
            'rows' => $rows,
            'record_count' => $recordCount,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
        ];
    }
    public static function fingerprint(array $row): string
    {
        // FlFlag is mutable sync state, not an event identity component.
        $values = [];
        foreach (array_diff(self::FIELDS, ['FlFlag']) as $field) {
            $values[$field] = isset($row[$field]) ? (string) $row[$field] : null;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public static function timestamp(array $row): string
    {
        $raw = ($row['FdDate'] ?? '') . ' ' . ($row['FcTime'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta')));
        if (!$date || $date->format('Y-m-d H:i:s') !== $raw) {
            compat_throw(new RuntimeException('Invalid/null legacy event timestamp; review source.'));
        }
        return $date->format('c');
    }
    /**
     * Production DB access is limited to the explicit localhost simulator trial.
     * This does not authorize the physical receiver or Talenta submission.
     */
    public static function allowsProductionSimulatorTrial(array $event, string $sourceIp, ?string $trialDirection): bool
    {
        return env('DB_DATABASE') === 'mkm_ssmdb'
            && env('INTEGRATION_STORAGE') === 'legacy_app'
            && enabled('LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED')
            && enabled('MACHINE_ALLOW_SIMULATOR')
            && enabled('MACHINE_RECEIVER_ENABLED')
            && in_array($sourceIp, ['127.0.0.1', '::1'], true)
            && ($event['card_number'] ?? '') === '2120'
            && is_string($trialDirection)
            && preg_match('/\A[0-9]\z/', $trialDirection) === 1;
    }
    /** Production physical writes require the explicit gate, transport allowlist and verified direction map. */
    public static function allowsProductionPhysicalReceiver(array $event, string $sourceIp, ?string $trialDirection): bool
    {
        $map = json_decode(env('LEGACY_DIRECTION_MAP', '{}'), true);
        $direction = is_array($map) ? ($map[$event['direction_code'] ?? ''] ?? null) : null;
        return env('DB_DATABASE') === 'mkm_ssmdb'
            && env('INTEGRATION_STORAGE') === 'legacy_app'
            && enabled('LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED')
            && enabled('MACHINE_RECEIVER_ENABLED')
            && $trialDirection === null
            && ReceiverHttp::sourceAllowed($sourceIp)
            && is_string($direction)
            && preg_match('/\A[0-9]\z/', $direction) === 1;
    }
    /** Set flag only after API acceptance, on exact unambiguous event tuples. */
    public function markAccepted(array $rows): void
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app' || !enabled('ALLOW_PRODUCTION_WRITE') || enabled('DRY_RUN')) {
            compat_throw(new RuntimeException('Flag writes are disabled.'));
        }
        if (Database::driver() === 'mysql') {
            $stmt = $this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table');
            $stmt->execute(['db' => env('DB_DATABASE'), 'table' => 'tbl_rawdata']);
            if (strcasecmp((string) $stmt->fetchColumn(), 'InnoDB') !== 0) {
                compat_throw(new RuntimeException('Atomic flag update requires InnoDB.'));
            }
        }
        $outer = $this->pdo->inTransaction();
        if ($outer) {
            $this->pdo->exec('SAVEPOINT legacy_flag_update');
        } else {
            $this->pdo->beginTransaction();
        }
        try {
            foreach ($rows as $row) {
                $where = [];
                $params = [];
                foreach (array_diff(self::FIELDS, ['FlFlag']) as $field) {
                    if ($row[$field] === null) {
                        compat_throw(new RuntimeException('Null event field.'));
                    }
                    $where[] = Database::quote($field) . '=:' . $field;
                    $params[$field] = $row[$field];
                }
                $predicate = implode(' AND ', $where);
                $stmt = $this->pdo->prepare('SELECT ' . Database::quote('FlFlag') . ' FROM ' . $this->table() . ' WHERE ' . $predicate . ' FOR UPDATE');
                $stmt->execute($params);
                $flags = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (count($flags) !== 1 || (string) $flags[0] !== '0') {
                    compat_throw(new RuntimeException('Source changed or ambiguous; flag update refused.'));
                }
                $stmt = $this->pdo->prepare('UPDATE ' . $this->table() . ' SET ' . Database::quote('FlFlag') . '=1 WHERE ' . $predicate . ' AND ' . Database::quote('FlFlag') . '=0');
                $stmt->execute($params);
                if ($stmt->rowCount() !== 1) {
                    compat_throw(new RuntimeException('Unexpected update count.'));
                }
            }
            if ($outer) {
                $this->pdo->exec('RELEASE SAVEPOINT legacy_flag_update');
            } else {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($outer) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT legacy_flag_update');
                $this->pdo->exec('RELEASE SAVEPOINT legacy_flag_update');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            compat_throw($e);
        }
    }
    public function store(array $event, array $payload, string $sourceIp, ?string $trialDirection = null): array
    {
        $productionDatabase = env('DB_DATABASE') === 'mkm_ssmdb';
        $productionTrial = self::allowsProductionSimulatorTrial($event, $sourceIp, $trialDirection);
        $productionPhysical = self::allowsProductionPhysicalReceiver($event, $sourceIp, $trialDirection);
        if (!in_array(env('INTEGRATION_STORAGE'), ['legacy_local', 'legacy_app'], true) || !enabled('LEGACY_LOCAL_WRITE') || !in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true) || ($productionDatabase && !$productionTrial && !$productionPhysical)) {
            compat_throw(new RuntimeException('Legacy source is read-only; local write requires an isolated non-production database.'));
        }
        $this->inspect();
        $map = json_decode(env('LEGACY_DIRECTION_MAP', '{}'), true, 512, JSON_THROW_ON_ERROR);
        if ($trialDirection !== null && ($event['card_number'] !== '2120' || !enabled('MACHINE_ALLOW_SIMULATOR'))) {
            compat_throw(new InvalidArgumentException('Manual trial direction requires simulator card 2120.'));
        }
        $direction = $trialDirection ?? $map[$event['direction_code']] ?? null;
        if (!is_string($direction) || strlen($direction) !== 1) {
            compat_throw(new RuntimeException('Configure a verified protocol-to-legacy direction mapping; no truncation is allowed.'));
        }
        $time = new DateTimeImmutable($event['event_datetime']);
        $row = ['FdDate' => $time->format('Y-m-d'), 'FcTime' => $time->format('H:i:s'), 'FcCardNo' => $event['card_number'], 'FcDirection' => $direction, 'FcTermNo' => $event['terminal_id'], 'FlFlag' => 0];
        // Cooperative cross-process lock, shared by all new receivers. Legacy writers do not honor it.
        $key = 'mkm-legacy-receiver-' . substr(hash('sha256', env('DB_DATABASE')), 0, 24);
        $mysql = Database::driver() === 'mysql';
        if ($this->pdo->inTransaction()) {
            compat_throw(new RuntimeException('Receiver requires its own transaction.'));
        }
        try {
            if ($mysql) {
                $s = $this->pdo->prepare('SELECT GET_LOCK(:name,5)');
                $s->execute(['name' => $key]);
                if ((int) $s->fetchColumn() !== 1) {
                    compat_throw(new RuntimeException('Receiver lock unavailable.'));
                }
            }
            $this->pdo->beginTransaction();
            if (!$mysql) {
                $this->pdo->exec('SELECT pg_advisory_xact_lock(18421028)');
            }
            $q = [Database::class, 'quote'];
            $where = [];
            foreach (array_diff(self::FIELDS, ['FlFlag']) as $field) {
                $where[] = $q($field) . '=:' . $field;
            }
            $s = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE ' . implode(' AND ', $where));
            $s->execute(array_diff_key($row, ['FlFlag' => true]));
            if ((int) $s->fetchColumn() > 0) {
                $this->pdo->commit();
                return ['id' => null, 'duplicate' => true, 'value_group_hash' => self::fingerprint($row)];
            }
            $fields = implode(',', array_map($q, self::FIELDS));
            $holders = implode(',', array_map(fn($f) => ':' . $f, self::FIELDS));
            $s = $this->pdo->prepare('INSERT INTO ' . $this->table() . ' (' . $fields . ') VALUES (' . $holders . ')');
            $s->execute($row);
            $this->pdo->commit();
            return ['id' => null, 'duplicate' => false, 'value_group_hash' => self::fingerprint($row)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            compat_throw($error);
        } finally {
            if ($mysql) {
                $s = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $s->execute(['name' => $key]);
            }
        }
    }
}
