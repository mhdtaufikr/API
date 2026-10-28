<?php

declare (strict_types=1);
require_once __DIR__ . '/compat.php';
const ROOT = __DIR__ . '/..';
ini_set('display_errors', '0');
function config(): array
{
    static $values;
    if ($values !== null) {
        return $values;
    }
    $values = [];
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    $values += ['INTEGRATION_STORAGE' => 'integration', 'LEGACY_LOCAL_WRITE' => 'false', 'LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED' => 'false', 'LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED' => 'false', 'LEGACY_DIRECTION_MAP' => '{}', 'LEGACY_SCHEMA' => 'public', 'HRMS_EXPORT_ON_RECEIVE' => 'false'];
    foreach ($values as $key => $value) {
        $override = getenv($key);
        if ($override !== false) {
            $values[$key] = $override;
        }
    }
    return $values;
}
function env(string $key, string $default = ''): string
{
    $value = config()[$key] ?? $default;
    if (class_exists('IntegrationSettings', false) && array_key_exists($key, IntegrationSettings::FIELDS)) {
        return IntegrationSettings::value($key, $value);
    }
    return $value;
}
function enabled(string $key): bool
{
    return strtolower(env($key)) === 'true';
}
function jsonFile(string $path): array
{
    if (!is_file($path)) {
        compat_throw(new RuntimeException('Required verified configuration is missing: ' . basename($path)));
    }
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function saveJson(string $path, array $value): void
{
    $temp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temp, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($temp, $path)) {
        compat_throw(new RuntimeException('Cannot persist configuration.'));
    }
}
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
date_default_timezone_set(env('ATTENDANCE_TIMEZONE', 'Asia/Jakarta'));
require __DIR__ . '/Attendance.php';
require __DIR__ . '/Database.php';
require __DIR__ . '/DatabaseConnection.php';
require __DIR__ . '/Mekari.php';
require __DIR__ . '/MachineRequestParser.php';
require __DIR__ . '/ReceiverAudit.php';
require __DIR__ . '/MachineReceiverService.php';
require __DIR__ . '/ReceiverHttp.php';
require __DIR__ . '/AttendanceRepository.php';
require __DIR__ . '/AttendanceProcessor.php';
require __DIR__ . '/TalentaApiService.php';
require __DIR__ . '/IntegrationMigrations.php';
require __DIR__ . '/CredentialCipher.php';
require __DIR__ . '/IntegrationSettings.php';
require __DIR__ . '/AttendanceShiftResolver.php';
require __DIR__ . '/WorkCalendarService.php';
require __DIR__ . '/AttendanceStatusService.php';
require __DIR__ . '/AttendanceEngineRepository.php';
require __DIR__ . '/AttendanceProcessingCommand.php';
require __DIR__ . '/TalentaAttendanceReader.php';
require __DIR__ . '/TalentaScheduledExport.php';
require_once __DIR__ . '/LegacyConfigMigration.php';
require_once __DIR__ . '/TrialSimulation.php';
require_once __DIR__ . '/LegacyAttendanceRepository.php';
require_once __DIR__ . '/LegacyManualSend.php';
require_once __DIR__ . '/LegacyTrialSetup.php';
require_once __DIR__ . '/TalentaEmployeeResolver.php';
require_once __DIR__ . '/HrmsSdfExport.php';
