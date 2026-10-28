<?php

declare (strict_types=1);
final class TalentaApiService
{
    private AttendanceRepository $repository;
    public function __construct(AttendanceRepository $repository)
    {
        $this->repository = $repository;
    }
    public function preview(int $queueId): array
    {
        $entry = $this->repository->queueEntry($queueId);
        $saved = $entry['snapshot'];
        if ($entry['status'] !== 'pending_review') {
            compat_throw(new RuntimeException('Queue entry already attempted; reconcile its outcome.'));
        }
        if (env('ATTENDANCE_TEST_NIK') === '' || $saved['employee_nik'] !== env('ATTENDANCE_TEST_NIK')) {
            compat_throw(new RuntimeException('Queue is restricted to the configured test NIK.'));
        }
        $groups = (new AttendanceProcessor())->process($this->repository->snapshot($entry['shift_date'], $entry['shift_date']), $entry['shift_date'], $entry['shift_date'], env('ATTENDANCE_TEST_NIK'));
        $current = null;
        foreach ($groups as $group) {
            if (hash_equals($entry['batch_hash'], $group['batch_hash'])) {
                $current = $group;
            }
        }
        if (!$current || $current['status'] !== 'ready_for_review') {
            compat_throw(new RuntimeException('Source events, shift, direction, or employee mapping changed. Queue needs manual review.'));
        }
        $engine = new AttendanceEngineRepository($this->repository->db());
        if ($engine->installed() && !$engine->exportable($current)) {
            compat_throw(new RuntimeException('Persisted attendance is missing, stale or protected. Process/review the attendance date before export.'));
        }
        $contract = Mekari::contract();
        if (!in_array('badge', array_column($contract['csv_columns'], 'source'), true) || in_array('nik', array_column($contract['csv_columns'], 'source'), true)) {
            compat_throw(new RuntimeException('Talenta CSV must use an explicitly mapped badge; NIK is not a badge number.'));
        }
        $events = $current['events'];
        return ['entry' => $entry, 'group' => $current, 'events' => $events, 'csv' => Mekari::importCsv($events, $contract)];
    }
    public function submit(int $queueId, string $previewHash, string $confirmation, bool $acknowledged): array
    {
        if (!Mekari::ready()) {
            compat_throw(new RuntimeException('Live Talenta submissions are disabled.'));
        }
        if (!$acknowledged || $confirmation !== 'SEND ' . env('ATTENDANCE_TEST_NIK')) {
            compat_throw(new RuntimeException('Explicit confirmation is required.'));
        }
        $preview = $this->preview($queueId);
        if (!hash_equals($previewHash, hash('sha256', $preview['entry']['batch_hash'] . $preview['csv']))) {
            compat_throw(new RuntimeException('Preview changed; review the CSV again.'));
        }
        if ($preview['group']['mapping']['talenta_user_id'] !== env('TALENTA_USER_ID')) {
            compat_throw(new RuntimeException('Mapped Talenta User ID differs from the configured trial user.'));
        }
        $this->repository->reserve($queueId);
        try {
            $response = Mekari::submit($preview['events']);
            $this->repository->finish($queueId, $response);
            return $response;
        } catch (Throwable $error) {
            // Reservation is never automatically cleared; remote outcome can be unknown.
            try {
                $this->repository->finish($queueId, ['status' => 'unknown', 'response' => 'Attempt stopped; reconcile before retry.']);
            } catch (Throwable $_ignored) {
            }
            compat_throw($error);
        }
    }
}
