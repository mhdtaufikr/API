<?php

declare (strict_types=1);
// Small PHP 7.4 compatibility layer, loaded before application classes.
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $i = 0;
        foreach ($array as $key => $value) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }
}
function compat_throw(Throwable $error)
{
    throw $error;
}
function compat_datetime_errors(): bool {
    $errors=DateTimeImmutable::getLastErrors();
    return $errors!==false&&($errors['warning_count']>0||$errors['error_count']>0);
}
function compat_match($value, array $arms)
{
    $default = null;
    foreach ($arms as $arm) {
        if ($arm[0] === null) {
            $default = $arm[1];
            continue;
        }
        if (in_array($value, $arm[0], true)) {
            return $arm[1]();
        }
    }
    if ($default !== null) {
        return $default();
    }
    compat_throw(new LogicException('Unhandled match value.'));
}
function compat_csv_row($stream, array $row): void
{
    $temp = fopen('php://temp', 'r+');
    if (!$temp) {
        compat_throw(new RuntimeException('CSV buffer unavailable.'));
    }
    try {
        if (fputcsv($temp, $row, ',', '"', '') === false) {
            compat_throw(new RuntimeException('CSV encoding failed.'));
        }
        rewind($temp);
        $line = stream_get_contents($temp);
        $line = substr($line, 0, -1) . "\r\n";
        if (fwrite($stream, $line) !== strlen($line)) {
            compat_throw(new RuntimeException('CSV write failed.'));
        }
    } finally {
        fclose($temp);
    }
}
