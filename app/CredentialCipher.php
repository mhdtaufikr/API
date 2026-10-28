<?php

declare (strict_types=1);
final class CredentialCipher
{
    private string $key;
    public function __construct(string $encodedKey)
    {
        $key = str_starts_with($encodedKey, 'base64:') ? base64_decode(substr($encodedKey, 7), true) : false;
        if ($key === false || strlen($key) !== 32) {
            compat_throw(new RuntimeException('APP_KEY is missing or invalid. Run php bin/console.php key:generate.'));
        }
        $this->key = $key;
    }
    public function encrypt(string $plain, string $field): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'mkm-talenta|' . $field, 16);
        if ($cipher === false) {
            compat_throw(new RuntimeException('Credential encryption failed.'));
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }
    public function decrypt(string $encrypted, string $field): string
    {
        $bytes = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
        if ($bytes === false || strlen($bytes) < 28) {
            compat_throw(new RuntimeException('Stored credentials cannot be decrypted. Check APP_KEY.'));
        }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'mkm-talenta|' . $field);
        if ($plain === false) {
            compat_throw(new RuntimeException('Stored credentials cannot be decrypted. Check APP_KEY.'));
        }
        return $plain;
    }
    public static function ensureKey(): string
    {
        if (env('APP_KEY') !== '') {
            new self(env('APP_KEY'));
            return env('APP_KEY');
        }
        $path = ROOT . '/.env';
        $lock = fopen(ROOT . '/storage/app-key.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            compat_throw(new RuntimeException('Cannot lock application key configuration.'));
        }
        try {
            $content = is_file($path) ? file_get_contents($path) : false;
            if ($content === false) {
                compat_throw(new RuntimeException('Create .env from the appropriate example first.'));
            }
            if (preg_match('/^APP_KEY=(.+)$/m', $content, $match) && trim($match[1]) !== '') {
                $key = trim(trim($match[1]), "\"'");
                new self($key);
                return $key;
            }
            $key = 'base64:' . base64_encode(random_bytes(32));
            if (preg_match('/^APP_KEY=.*$/m', $content)) {
                $content = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $content, 1);
            } else {
                $content = rtrim($content) . "\nAPP_KEY=" . $key . "\n";
            }
            $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $file = fopen($temp, 'xb');
            if (!$file) {
                compat_throw(new RuntimeException('Cannot write APP_KEY into .env.'));
            }
            try {
                if (DIRECTORY_SEPARATOR === '/') {
                    chmod($temp, 0600);
                }
                if (fwrite($file, $content) !== strlen($content) || !fflush($file)) {
                    compat_throw(new RuntimeException('Cannot persist APP_KEY.'));
                }
                if (function_exists('fsync') && !fsync($file)) {
                    compat_throw(new RuntimeException('Cannot persist APP_KEY.'));
                }
            } finally {
                fclose($file);
            }
            if (!rename($temp, $path)) {
                compat_throw(new RuntimeException('Cannot commit APP_KEY into .env.'));
            }
            return $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
