<?php

declare (strict_types=1);
final class Database
{
    public static function driver(): string
    {
        return env('DB_CONNECTION', 'pgsql');
    }
    public static function migrationFile(): string
    {
        return ROOT . '/migrations/001_machine_integration.' . (self::driver() === 'mysql' ? 'mysql.sql' : 'sql');
    }
    public static function connect(): PDO
    {
        $pdo = self::open();
        $pdo->exec(self::driver() === 'mysql' ? 'SET SESSION TRANSACTION READ ONLY' : 'SET default_transaction_read_only = on');
        $pdo->beginTransaction();
        if (self::driver() === 'pgsql') {
            $pdo->exec('SET TRANSACTION READ ONLY');
        }
        return $pdo;
    }
    public static function open(string $purpose = 'base'): PDO
    {
        $driver = self::driver();
        if (!in_array($driver, ['pgsql', 'mysql'], true)) {
            compat_throw(new RuntimeException('DB_CONNECTION must be pgsql or mysql.'));
        }
        if (!extension_loaded('pdo_' . $driver)) {
            compat_throw(new RuntimeException('Enable pdo_' . $driver . ' in the active PHP runtime.'));
        }
        if (env('DB_DATABASE') === '') {
            compat_throw(new RuntimeException('Set DB_DATABASE and database credentials in .env.'));
        }
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE'] as $key) {
            if (preg_match('/[;\r\n]/', env($key))) {
                compat_throw(new RuntimeException('Invalid database configuration.'));
            }
        }
        if (!in_array($purpose, ['base', 'reader', 'receiver', 'worker', 'settings'], true)) {
            compat_throw(new InvalidArgumentException('Invalid database connection purpose.'));
        }
        $prefix = 'DB_' . strtoupper($purpose);
        $username = env($prefix . '_USERNAME') !== '' ? env($prefix . '_USERNAME') : env('DB_USERNAME');
        $password = env($prefix . '_USERNAME') !== '' ? env($prefix . '_PASSWORD') : env('DB_PASSWORD');
        $dsn = $driver . ':host=' . env('DB_HOST') . ';port=' . env('DB_PORT') . ';dbname=' . env('DB_DATABASE') . ($driver === 'mysql' ? ';charset=utf8mb4' : ';connect_timeout=3');
        $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]);
        if ($driver === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
            if (str_contains((string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB')) {
                $pdo->exec('SET SESSION max_statement_time = 5');
            } else {
                $pdo->exec('SET SESSION max_execution_time = 5000');
            }
            if ($purpose === 'reader' || env('INTEGRATION_STORAGE') === 'legacy_readonly') {
                $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            }
        } else {
            $pdo->exec("SET statement_timeout = '5000ms'");
            $pdo->exec("SET lock_timeout = '2000ms'");
            if ($purpose === 'reader' || env('INTEGRATION_STORAGE') === 'legacy_readonly') {
                $pdo->exec('SET default_transaction_read_only = on');
            }
        }
        return $pdo;
    }
    public static function columns(PDO $pdo): array
    {
        if (self::driver() === 'mysql') {
            return $pdo->query("SELECT table_schema AS table_schema,table_name AS table_name,column_name AS column_name,data_type AS data_type,column_type AS udt_name FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query("SELECT table_schema, table_name, column_name, data_type, udt_name FROM information_schema.columns WHERE table_schema NOT IN ('pg_catalog', 'information_schema') ORDER BY table_schema, table_name, ordinal_position")->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function quote(string $identifier): string
    {
        $q = self::driver() === 'mysql' ? '`' : '"';
        return $q . str_replace($q, $q . $q, $identifier) . $q;
    }
    public static function rows(string $from, string $to): array
    {
        if (env('ATTENDANCE_TEST_NIK') === '') {
            compat_throw(new RuntimeException('Set one ATTENDANCE_TEST_NIK in .env.'));
        }
        $map = jsonFile(ROOT . '/config/source.json');
        $pdo = self::connect();
        $columns = self::columns($pdo);
        $available = array_column(array_filter($columns, fn($c) => $c['table_schema'] === $map['schema'] && $c['table_name'] === $map['table']), 'data_type', 'column_name');
        foreach (['nik', 'timestamp', 'direction'] as $field) {
            if (!isset($map[$field], $available[$map[$field]])) {
                compat_throw(new RuntimeException('Source mapping does not match the discovered database schema.'));
            }
        }
        if (!in_array($available[$map['timestamp']], ['timestamp without time zone', 'timestamp with time zone', 'timestamp', 'datetime'], true)) {
            compat_throw(new RuntimeException('Mapped timestamp must be a database timestamp column.'));
        }
        $q = fn($s) => self::quote($s);
        $sql = 'SELECT ' . $q($map['nik']) . '::text AS nik, ' . $q($map['timestamp']) . '::text AS timestamp, ' . $q($map['direction']) . '::text AS direction FROM ' . $q($map['schema']) . '.' . $q($map['table']) . ' WHERE ' . $q($map['nik']) . '::text = :nik AND ' . $q($map['timestamp']) . ' >= :start AND ' . $q($map['timestamp']) . ' < :end ORDER BY ' . $q($map['timestamp']) . ' LIMIT 1001';
        if (self::driver() === 'mysql') {
            $sql = preg_replace('/(`[^`]+`)::text/', 'CAST($1 AS CHAR)', $sql);
        }
        $tz = new DateTimeZone(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'));
        $start = new DateTimeImmutable($from, $tz);
        $end = (new DateTimeImmutable($to, $tz))->modify('+1 day');
        $aware = $available[$map['timestamp']] === 'timestamp with time zone';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['nik' => env('ATTENDANCE_TEST_NIK'), 'start' => $start->format($aware ? 'c' : 'Y-m-d H:i:s'), 'end' => $end->format($aware ? 'c' : 'Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pdo->rollBack();
        if (count($rows) > 1000) {
            compat_throw(new RuntimeException('More than 1000 records; narrow the date range.'));
        }
        foreach ($rows as &$row) {
            $row['direction'] = $map['directions'][(string) $row['direction']] ?? 'UNKNOWN';
        }
        return $rows;
    }
}
