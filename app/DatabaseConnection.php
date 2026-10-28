<?php
declare(strict_types=1);

final class DatabaseConnection {
    /** Explicit configured PDO driver; no database fallback. */
    public static function getConnection(string $purpose='reader'): PDO { return Database::open($purpose); }
}
