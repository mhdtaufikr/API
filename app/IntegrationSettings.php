<?php

declare (strict_types=1);
final class IntegrationSettings
{
    private PDO $pdo;
    private string $key;
    public const FIELDS = ['MEKARI_CLIENT_ID' => 'client_id_encrypted', 'MEKARI_CLIENT_SECRET' => 'client_secret_encrypted', 'MEKARI_IMPORT_TOKEN' => 'import_token_encrypted'];
    private static ?array $resolved = null;
    private static ?string $failure = null;
    public function __construct(PDO $pdo, string $key)
    {
        $this->pdo = $pdo;
        $this->key = $key;
    }
    public function row(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . IntegrationMigrations::table('integration_settings') . ' WHERE provider=:provider');
        $stmt->execute(['provider' => 'talenta']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function seed(): bool
    {
        new CredentialCipher($this->key);
        $sql = 'INSERT INTO ' . IntegrationMigrations::table('integration_settings') . (LegacyConfigMigration::active() ? " (provider,audit_history) VALUES (:provider,'[]')" : ' (provider) VALUES (:provider)');
        $sql .= Database::driver() === 'mysql' ? ' ON DUPLICATE KEY UPDATE provider=VALUES(provider)' : ' ON CONFLICT (provider) DO NOTHING';
        $before = $this->row();
        if ($before) {
            $this->values();
            return false;
        }
        $outer = $this->pdo->inTransaction();
        if (!$outer) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['provider' => 'talenta']);
            $created = $stmt->rowCount() === 1;
            if ($created) {
                $this->audit('seed', []);
            }
            if (!$outer) {
                $this->pdo->commit();
            }
            self::reset();
            return $created;
        } catch (Throwable $error) {
            if (!$outer && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            compat_throw($error);
        }
    }
    public function values(): ?array
    {
        $row = $this->row();
        if (!$row) {
            return null;
        }
        $cipher = new CredentialCipher($this->key);
        $result = [];
        foreach (self::FIELDS as $key => $column) {
            $result[$key] = $row[$column] === null ? '' : $cipher->decrypt($row[$column], $key);
        }
        return $result;
    }
    public function save(array $input, array $clear, int $revision): void
    {
        $cipher = new CredentialCipher($this->key);
        $this->values();
        $changes = [];
        foreach (self::FIELDS as $key => $column) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f"]/', $value)) {
                compat_throw(new InvalidArgumentException('Credentials must be single-line values, without quotes/control characters, maximum 4096 bytes.'));
            }
            if (!empty($clear[$key]) && $value !== '') {
                compat_throw(new InvalidArgumentException('Choose either replace or clear for each credential.'));
            }
            if (!empty($clear[$key])) {
                $changes[$column] = null;
            } elseif ($value !== '') {
                $changes[$column] = $cipher->encrypt($value, $key);
            }
        }
        if (!$changes) {
            compat_throw(new InvalidArgumentException('Enter a new credential or select a credential to clear.'));
        }
        $outer = $this->pdo->inTransaction();
        if ($outer) {
            $this->pdo->exec('SAVEPOINT mkm_settings');
        } else {
            $this->pdo->beginTransaction();
        }
        $complete = function (bool $commit) use ($outer): void {
            if ($outer) {
                if (!$commit) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT mkm_settings');
                }
                $this->pdo->exec('RELEASE SAVEPOINT mkm_settings');
            } elseif ($this->pdo->inTransaction()) {
                if ($commit) {
                    $this->pdo->commit();
                } else {
                    $this->pdo->rollBack();
                }
            }
        };
        try {
            $sets = [];
            $params = ['provider' => 'talenta', 'revision' => $revision];
            foreach ($changes as $column => $value) {
                $sets[] = $column . '=:' . $column;
                $params[$column] = $value;
            }
            $sql = 'UPDATE ' . IntegrationMigrations::table('integration_settings') . ' SET ' . implode(',', $sets) . ',revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE provider=:provider AND revision=:revision';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                compat_throw(new RuntimeException('Settings changed in another session. Reload before saving.'));
            }
            $this->audit('update', array_keys($changes));
            $complete(true);
            self::reset();
        } catch (Throwable $error) {
            $complete(false);
            compat_throw($error);
        }
    }
    private function audit(string $action, array $fields): void
    {
        if (LegacyConfigMigration::active()) {
            $expression=Database::driver()==='pgsql'?"audit_history || jsonb_build_array(jsonb_build_object('action',CAST(:action AS text),'fields',CAST(:fields AS jsonb),'at',CAST(:at AS text)))":"JSON_ARRAY_APPEND(audit_history,'\$',JSON_OBJECT('action',:action,'fields',:fields,'at',:at))";
            $stmt = $this->pdo->prepare('UPDATE '.LegacyConfigMigration::table().' SET audit_history='.$expression.' WHERE provider=:provider');
            $stmt->execute(['action' => $action, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR), 'at' => gmdate('c'), 'provider' => 'talenta']);
            return;
        }
        $json = Database::driver() === 'mysql' ? ':fields' : 'CAST(:fields AS jsonb)';
        $stmt = $this->pdo->prepare('INSERT INTO ' . IntegrationMigrations::table('integration_settings_audit') . ' (provider,action,changed_fields) VALUES (:provider,:action,' . $json . ')');
        $stmt->execute(['provider' => 'talenta', 'action' => $action, 'fields' => json_encode($fields, JSON_THROW_ON_ERROR)]);
    }
    public static function reset(): void
    {
        self::$resolved = null;
        self::$failure = null;
    }
    public static function value(string $key, string $fallback): string
    {
        if (LegacyAttendanceRepository::active() && env('INTEGRATION_STORAGE') !== 'legacy_app') {
            return $fallback;
        }
        if (self::$resolved === null) {
            self::$resolved = [];
            try {
                $service = new self(Database::open('reader'), config()['APP_KEY'] ?? '');
                $row = $service->row();
                if ($row !== null) {
                    self::$resolved = $service->values() ?? [];
                }
            } catch (PDOException $error) {
                // Before migration 002, preserve existing .env-only installations and sample mode.
                if (!in_array($error->getCode(), ['42S02', '42P01'], true)) {
                    self::$failure = 'Credential database is unavailable.';
                    self::$resolved = array_fill_keys(array_keys(self::FIELDS), '');
                }
            } catch (Throwable $_ignored) {
                self::$failure = 'Credentials cannot be decrypted. Verify APP_KEY.';
                self::$resolved = array_fill_keys(array_keys(self::FIELDS), '');
            }
        }
        return self::$resolved[$key] ?? $fallback;
    }
    public static function fingerprint(): string
    {
        $values = [];
        foreach (self::FIELDS as $key => $column) {
            $values[$key] = env($key);
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
