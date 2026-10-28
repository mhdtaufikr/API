<?php

declare (strict_types=1);
final class ReceiverAudit
{
    private string $path;
    public function __construct(string $path = ROOT . '/storage/receiver.jsonl')
    {
        $this->path = $path;
    }
    public function append(array $entry): void
    {
        $entry['at'] = gmdate('c');
        $line = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $f = fopen($this->path, 'ab');
        if (!$f || !flock($f, LOCK_EX)) {
            compat_throw(new RuntimeException('Receiver audit unavailable.'));
        }
        try {
            if (fwrite($f, $line) !== strlen($line) || !fflush($f)) {
                compat_throw(new RuntimeException('Receiver audit write failed.'));
            }
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
    public function summary(?string $requestId = null): array
    {
        $stats = ['received' => 0, 'stored' => 0, 'duplicate' => 0, 'rejected' => 0, 'error' => 0, 'last' => null, 'entry' => null];
        if (!is_file($this->path)) {
            return $stats;
        }
        $f = fopen($this->path, 'rb');
        if (!$f || !flock($f, LOCK_SH)) {
            compat_throw(new RuntimeException('Cannot read receiver audit.'));
        }
        try {
            while (($line = fgets($f)) !== false) {
                $item = json_decode($line, true);
                if (!is_array($item) || !isset($item['status'])) {
                    continue;
                }
                $stats['received']++;
                if (array_key_exists($item['status'], $stats)) {
                    $stats[$item['status']]++;
                }
                $stats['last'] = $item;
                if ($requestId !== null && ($item['request_id'] ?? '') === $requestId) {
                    $stats['entry'] = $item;
                }
            }
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
        return $stats;
    }
}
