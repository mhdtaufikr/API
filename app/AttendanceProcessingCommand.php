<?php

declare (strict_types=1);
final class AttendanceProcessingCommand
{
    private AttendanceEngineRepository $engine;
    private AttendanceRepository $source;
    private AttendanceProcessor $processor;
    public function __construct(AttendanceEngineRepository $engine, AttendanceRepository $source, AttendanceProcessor $processor)
    {
        $this->engine = $engine;
        $this->source = $source;
        $this->processor = $processor;
    }
    public function run(string $from, string $to, ?string $employee = null, bool $dryRun = false, ?DateTimeImmutable $now = null): array
    {
        AttendanceRepository::dateRange($from, $to);
        $this->engine->requireInstalled();
        $employees = $this->engine->employees($employee);
        if ($employee !== null && !$employees) {
            compat_throw(new InvalidArgumentException('Requested employee has no explicit card/NIK mapping.'));
        }
        $summary = ['dry_run' => $dryRun, 'employees_processed' => 0, 'raw_logs_processed' => 0, 'attendance_created' => 0, 'attendance_updated' => 0, 'records_skipped' => 0, 'processing_errors' => 0, 'skip_reasons' => [], 'results' => []];
        $seen = [];
        foreach ($employees as $nik) {
            $summary['employees_processed']++;
            for ($day = new DateTimeImmutable($from); $day <= new DateTimeImmutable($to); $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                try {
                    $snapshot = $this->source->snapshot($date, $date, $nik);
                    $records = $this->processor->calculate($snapshot, $date, $date, $now);
                    $records = array_values(array_filter($records, fn($record) => $record['employee_nik'] === $nik));
                    if (count($records) !== 1) {
                        compat_throw(new RuntimeException('Employee/date processing did not resolve one attendance record.'));
                    }
                    $record = $records[0];
                    foreach ($record['source_log_ids'] as $id) {
                        $seen[$id] = true;
                    }
                    $action = $this->engine->persist($record, $dryRun);
                    if (in_array($action, ['created', 'would_created'], true)) {
                        $summary['attendance_created']++;
                    } elseif (in_array($action, ['updated', 'would_updated'], true)) {
                        $summary['attendance_updated']++;
                    } else {
                        $summary['records_skipped']++;
                        $summary['skip_reasons'][$action] = ($summary['skip_reasons'][$action] ?? 0) + 1;
                    }
                    $stored = $this->engine->find($nik, $date);
                    $summary['results'][] = ['employee_nik' => $nik, 'date' => $date, 'action' => $action, 'calculated_status' => $record['final_attendance_status'], 'stored_final_status' => $stored['final_attendance_status'] ?? null, 'stored_final_status_source' => $stored['final_status_source'] ?? null, 'validation' => $record['validation_status']];
                } catch (Throwable $error) {
                    $summary['processing_errors']++;
                    try {
                        $this->engine->logError($nik, $date, $error, $dryRun);
                    } catch (Throwable $_ignored) {
                    }
                    $summary['results'][] = ['employee_nik' => $nik, 'date' => $date, 'action' => 'error', 'message' => $error instanceof PDOException ? 'Database processing error' : $error->getMessage()];
                }
            }
        }
        $summary['raw_logs_processed'] = count($seen);
        return $summary;
    }
}
