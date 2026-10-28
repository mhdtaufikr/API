<?php

declare (strict_types=1);
final class TalentaScheduledExport
{
    public const TIMES = ['08:00', '12:00', '18:00'];
    public static function slot(DateTimeImmutable $now, int $window = 15): ?string
    {
        if ($window < 1 || $window > 59) {
            compat_throw(new InvalidArgumentException('Scheduler window must be 1..59 minutes.'));
        }
        $now = $now->setTimezone(new DateTimeZone('Asia/Jakarta'));
        foreach (self::TIMES as $time) {
            $start = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $time, new DateTimeZone('Asia/Jakarta'));
            if ($now >= $start && $now < $start->modify('+' . $window . ' minutes')) {
                return $now->format('Y-m-d') . '@' . $time;
            }
        }
        return null;
    }
    /** Select only real, unambiguous taps. An open shift may export a reliable IN before its OUT exists. */
    public static function eligibleEvents(array $record): array
    {
        $review = array_values(array_diff($record['review_reasons'], ['Shift window is not closed; attendance is provisional']));
        if ($review || $record['actual_clock_in'] === null) {
            return [];
        }
        $events = array_values(array_filter($record['events'], fn($event) => in_array($record['source_classifications'][$event['id']] ?? '', ['clock_in', 'clock_out'], true)));
        if ($record['actual_clock_out'] === null) {
            foreach ($events as $event) {
                if ($event['direction'] === 'IN' && !$event['direction_reliable']) {
                    return [];
                }
            }
        }
        return $events;
    }
    private static function key(): string
    {
        new CredentialCipher(env('APP_KEY'));
        return hash_hmac('sha256', 'mkm-talenta-scheduler-profile-v1', base64_decode(substr(env('APP_KEY'), 7), true), true);
    }
    public static function fingerprint(array $configuration): string
    {
        return hash('sha256', json_encode(['times' => self::TIMES, 'window' => env('TALENTA_SCHEDULE_WINDOW_MINUTES', '15'), 'lookback' => env('TALENTA_SCHEDULE_LOOKBACK_DAYS', '3'), 'company' => env('MEKARI_COMPANY_ID'), 'user' => env('TALENTA_USER_ID'), 'nik' => env('ATTENDANCE_TEST_NIK'), 'credentials' => IntegrationSettings::fingerprint(), 'contract' => Mekari::contract(), 'configuration' => $configuration], JSON_THROW_ON_ERROR));
    }
    public static function approve(AttendanceRepository $repository, string $confirmation): array
    {
        if ($confirmation !== 'SCHEDULED SEND ' . env('ATTENDANCE_TEST_NIK') || env('ATTENDANCE_TEST_NIK') === '') {
            compat_throw(new RuntimeException('Explicit profile approval must be: SCHEDULED SEND <configured NIK>.'));
        }
        if (!Mekari::ready()) {
            compat_throw(new RuntimeException('Verify credentials, CSV import profile, mapping and live-write gates before approving scheduled export.'));
        }
        $fingerprint = self::fingerprint($repository->schedulerConfiguration());
        $profile = ['fingerprint' => $fingerprint, 'approved_at' => gmdate('c'), 'company_id' => env('MEKARI_COMPANY_ID'), 'test_nik' => env('ATTENDANCE_TEST_NIK'), 'user_id' => env('TALENTA_USER_ID'), 'times' => self::TIMES];
        $profile['signature'] = hash_hmac('sha256', json_encode($profile, JSON_THROW_ON_ERROR), self::key());
        saveJson(ROOT . '/storage/scheduler-approval.json', $profile);
        return $profile;
    }
    private static function approved(string $fingerprint): void
    {
        self::validateApproval(jsonFile(ROOT . '/storage/scheduler-approval.json'), $fingerprint, self::key());
    }
    public static function validateApproval(array $profile, string $fingerprint, string $signingKey): void
    {
        $signature = $profile['signature'] ?? '';
        unset($profile['signature']);
        if (strlen($signingKey) !== 32 || !is_string($signature) || !hash_equals(hash_hmac('sha256', json_encode($profile, JSON_THROW_ON_ERROR), $signingKey), $signature) || !is_string($profile['fingerprint'] ?? null) || !hash_equals($profile['fingerprint'], $fingerprint)) {
            compat_throw(new RuntimeException('Scheduled export approval is missing, invalid or stale. Review and approve the current profile.'));
        }
    }
    public function run(bool $preview = false, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $window = filter_var(env('TALENTA_SCHEDULE_WINDOW_MINUTES', '15'), FILTER_VALIDATE_INT);
        $days = filter_var(env('TALENTA_SCHEDULE_LOOKBACK_DAYS', '3'), FILTER_VALIDATE_INT);
        if ($window === false || $days === false || $days < 1 || $days > 7) {
            compat_throw(new RuntimeException('Invalid scheduler window or lookback configuration (1..7 days).'));
        }
        $slot = self::slot($now, $window);
        $summary = ['preview' => $preview, 'timezone' => 'Asia/Jakarta', 'schedule' => self::TIMES, 'slot' => $slot, 'status' => 'preview', 'events_pending' => 0, 'batches_submitted' => 0, 'batches_held' => 0, 'errors' => 0];
        if (!$preview && !enabled('TALENTA_SCHEDULER_ENABLED')) {
            return array_replace($summary, ['status' => 'disabled', 'reason' => 'Scheduler disabled']);
        }
        if (!$preview && $slot === null) {
            return array_replace($summary, ['status' => 'outside_schedule']);
        }
        $lock = null;
        if (!$preview) {
            $lock = fopen(ROOT . '/storage/scheduler.lock', 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return array_replace($summary, ['status' => 'already_running']);
            }
        }
        try {
            $repository = new AttendanceRepository(null, $preview ? 'reader' : 'worker');
            $configuration = $repository->schedulerConfiguration();
            $fingerprint = self::fingerprint($configuration);
            $statePath = ROOT . '/storage/scheduler-state.json';
            $state = is_file($statePath) ? jsonFile($statePath) : [];
            if (!$preview) {
                if (!Mekari::ready()) {
                    compat_throw(new RuntimeException('Live Talenta write gates are not ready.'));
                }
                self::approved($fingerprint);
                if (isset($state[$slot])) {
                    return array_replace($summary, ['status' => 'slot_already_attempted']);
                }
            }
            $from = $now->modify('-' . $days . ' days')->format('Y-m-d');
            $to = $now->format('Y-m-d');
            $snapshot = $repository->snapshot($from, $to, env('ATTENDANCE_TEST_NIK'));
            $processor = new AttendanceProcessor();
            $engine = new AttendanceEngineRepository($repository->db());
            $engine->requireInstalled();
            $attempted = [];
            foreach (Mekari::logs() as $entry) {
                foreach ($entry['event_keys'] as $key) {
                    $attempted[$key] = true;
                }
            }
            $plans = [];
            foreach ($processor->calculate($snapshot, $from, $to, $now) as $record) {
                if ($processor->protectedDate($snapshot, $record['employee_nik'], $record['attendance_date'])) {
                    $summary['batches_held']++;
                    continue;
                }
                $events = self::eligibleEvents($record);
                if (!$events) {
                    if ($record['raw_log_count']) {
                        $summary['batches_held']++;
                    }
                    continue;
                }
                $claimed = $repository->claimedEvents(array_column($events, 'id'));
                $events = array_values(array_filter($events, fn($event) => !isset($attempted[Mekari::eventKey($event)]) && !in_array($event['id'], $claimed, true)));
                if (!$events) {
                    continue;
                }
                Mekari::importCsv($events, Mekari::contract());
                $summary['events_pending'] += count($events);
                $plans[] = [$record, $events];
            }
            if ($preview) {
                return $summary;
            }
            $state[$slot] = ['status' => 'reserved', 'at' => gmdate('c'), 'profile' => $fingerprint];
            saveJson($statePath, $state);
            foreach ($plans as [$record, $events]) {
                $queueId = null;
                try {
                    $persisted = $engine->persist($record);
                    if (in_array($persisted, ['skipped_locked', 'skipped_protected'], true)) {
                        $summary['batches_held']++;
                        continue;
                    }
                    self::approved(self::fingerprint($repository->schedulerConfiguration()));
                    $queueId = $repository->enqueueScheduled($record, $events, $fingerprint);
                    if ($queueId === null) {
                        continue;
                    }
                    $repository->reserve($queueId);
                    $response = Mekari::submit($events);
                    $repository->finish($queueId, $response);
                    if ($response['status'] === 'submitted_pending_reconciliation') {
                        $summary['batches_submitted']++;
                    } else {
                        $summary['errors']++;
                    }
                } catch (Throwable $error) {
                    $summary['errors']++;
                    if ($queueId !== null) {
                        try {
                            $repository->finish($queueId, ['status' => 'unknown', 'response' => 'Scheduled attempt stopped; reconcile before retry.']);
                        } catch (Throwable $_ignored) {
                        }
                    }
                }
            }
            $summary['status'] = $summary['errors'] ? 'completed_with_errors' : 'completed';
            $state[$slot] = $summary + ['at' => gmdate('c')];
            saveJson($statePath, $state);
            (new ReceiverAudit(ROOT . '/storage/scheduler.jsonl'))->append($summary);
            return $summary;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
