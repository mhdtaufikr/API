<?php

declare(strict_types=1);

final class LegacyTalentaScheduledExport
{
    /** @var callable */
    private $previewer;
    /** @var callable */
    private $sender;
    /** @var callable */
    private $logger;
    /** @var callable */
    private $batchProvider;

    public function __construct(?callable $previewer = null, ?callable $sender = null, ?callable $logger = null, ?callable $batchProvider = null)
    {
        $this->previewer = $previewer ?? static function (array $scope): array {
            $service = new LegacyManualSend(new LegacyAttendanceRepository(Database::open('reader')));
            return $service->previewScheduled($scope);
        };
        $this->sender = $sender ?? static function (array $scope, string $hash): array {
            $service = new LegacyManualSend(new LegacyAttendanceRepository(Database::open('worker')));
            return $service->sendScheduled($scope, $hash);
        };
        $this->logger = $logger ?? static function (array $summary): void {
            (new ReceiverAudit(ROOT . '/storage/legacy-scheduler.jsonl'))->append($summary);
        };
        $this->batchProvider = $batchProvider ?? static function (string $from, string $to, int $employeeBatch, int $rowBatch): iterable {
            $repository = new LegacyAttendanceRepository(Database::open('reader'));
            return $repository->scheduledScopes($from, $to, $employeeBatch, $rowBatch);
        };
    }

    public function run(bool $preview = false, ?DateTimeImmutable $now = null, bool $manual = false): array
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app') {
            compat_throw(new RuntimeException('Legacy scheduler requires INTEGRATION_STORAGE=legacy_app.'));
        }
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $window = filter_var(env('TALENTA_SCHEDULE_WINDOW_MINUTES', '15'), FILTER_VALIDATE_INT);
        $days = filter_var(env('TALENTA_SCHEDULE_LOOKBACK_DAYS', '3'), FILTER_VALIDATE_INT);
        $employeeBatch = filter_var(env('TALENTA_SCHEDULE_EMPLOYEE_BATCH_SIZE', '25'), FILTER_VALIDATE_INT);
        $rowBatch = filter_var(env('TALENTA_SCHEDULE_ROW_BATCH_SIZE', '500'), FILTER_VALIDATE_INT);
        if ($window === false || $window < 1 || $window > 59
            || $days === false || $days < 1 || $days > 7
            || $employeeBatch === false || $employeeBatch < 1 || $employeeBatch > 100
            || $rowBatch === false || $rowBatch < 1 || $rowBatch > 1000) {
            compat_throw(new RuntimeException('Invalid scheduler window, lookback or batch configuration.'));
        }

        $slot = TalentaScheduledExport::slot($now, $window);
        $summary = [
            'mode' => 'legacy_app',
            'preview' => $preview,
            'trigger' => $preview ? 'preview' : ($manual ? 'manual' : 'scheduled'),
            'timezone' => 'Asia/Jakarta',
            'schedule' => TalentaScheduledExport::TIMES,
            'slot' => $slot,
            'status' => 'preview',
            'employee_batch_size' => $employeeBatch,
            'row_batch_size' => $rowBatch,
            'total_pending_records' => 0,
            'total_unique_employees' => 0,
            'batches_created' => 0,
            'batches_submitted' => 0,
            'batches_failed' => 0,
            'batches_held' => 0,
            'flags_updated' => 0,
            'records_skipped' => 0,
            'records_held' => 0,
            'events_pending' => 0,
            'events_skipped' => 0,
            'cards_skipped' => 0,
            'errors' => 0,
        ];
        if (!$preview && !enabled('TALENTA_SCHEDULER_ENABLED')) {
            return $this->finish($summary, ['status' => 'disabled', 'reason' => 'Scheduler disabled']);
        }
        if (!$preview && !$manual && $slot === null) {
            return $this->finish($summary, ['status' => 'outside_schedule']);
        }

        $lock = null;
        if (!$preview) {
            $lock = fopen(ROOT . '/storage/legacy-scheduler.lock', 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->finish($summary, ['status' => 'already_running']);
            }
        }

        $employees = [];
        $skippedCards = [];
        $ledgerIds = [];
        $heldBatches = [];
        $failedBatches = [];
        $lastHttp = null;
        try {
            $from = $now->modify('-' . $days . ' days')->format('Y-m-d');
            $to = $now->format('Y-m-d');
            $scopes = ($this->batchProvider)($from, $to, $employeeBatch, $rowBatch);
            foreach ($scopes as $scope) {
                $summary['batches_created']++;
                $batchKey = (string) $summary['batches_created'];
                $summary['total_pending_records'] += (int) ($scope['record_count'] ?? 0);
                foreach ($scope['cards'] ?? [] as $card) {
                    $employees[hash('sha256', (string) $card)] = true;
                }
                try {
                    $plan = ($this->previewer)($scope);
                } catch (NoPendingLegacyAttendanceException $_changed) {
                    continue;
                } catch (Throwable $_previewError) {
                    $heldBatches[$batchKey] = true;
                    if (!$preview) {
                        $summary['errors']++;
                    }
                    continue;
                }

                $summary['events_pending'] += count($plan['events'] ?? []);
                $skipped = array_sum(array_column($plan['skipped'] ?? [], 'rows'));
                $held = array_sum(array_column($plan['held'] ?? [], 'rows'));
                $summary['records_skipped'] += $skipped;
                $summary['events_skipped'] = $summary['records_skipped'];
                $summary['records_held'] += $held;
                foreach ($plan['skipped'] ?? [] as $item) {
                    if (isset($item['card'])) {
                        $skippedCards[hash('sha256', (string) $item['card'])] = true;
                    }
                }
                $summary['cards_skipped'] = count($skippedCards);
                if ($held > 0) {
                    $heldBatches[$batchKey] = true;
                }
                if (!empty($plan['issues'])) {
                    $heldBatches[$batchKey] = true;
                    if (!$preview) {
                        $summary['errors']++;
                    }
                    continue;
                }
                if (empty($plan['events']) || $preview) {
                    continue;
                }

                try {
                    $result = ($this->sender)($scope, (string) $plan['hash']);
                } catch (Throwable $sendError) {
                    if (str_contains($sendError->getMessage(), 'Previously attempted events blocked')) {
                        $heldBatches[$batchKey] = true;
                    } else {
                        $failedBatches[$batchKey] = true;
                    }
                    $summary['errors']++;
                    continue;
                }
                $lastHttp = $result['http'] ?? $lastHttp;
                if (isset($result['id']) && is_string($result['id'])) {
                    $ledgerIds[] = $result['id'];
                }
                $resultStatus = (string) ($result['status'] ?? 'unknown');
                if ($resultStatus === 'submitted_pending_reconciliation') {
                    $summary['batches_submitted']++;
                    if (($result['flag_updated'] ?? false) === true) {
                        $summary['flags_updated'] += count($plan['rows'] ?? []);
                    } else {
                        $heldBatches[$batchKey] = true;
                        $summary['errors']++;
                    }
                } elseif (in_array($resultStatus, ['unknown', 'reserved'], true)) {
                    $heldBatches[$batchKey] = true;
                    $summary['errors']++;
                } else {
                    $failedBatches[$batchKey] = true;
                    $summary['errors']++;
                }
            }

            $summary['total_unique_employees'] = count($employees);
            $summary['batches_held'] = count($heldBatches);
            $summary['batches_failed'] = count($failedBatches);
            $changes = $this->finalStatus($summary);
            if (!$preview && $summary['total_pending_records'] > 0 && $summary['batches_submitted'] === 0 && $summary['errors'] === 0) {
                $changes['errors'] = 1;
            }
            if ($lastHttp !== null) {
                $changes['http'] = $lastHttp;
            }
            if ($ledgerIds) {
                $changes['ledger_ids'] = $ledgerIds;
            }
            return $this->finish($summary, $changes);
        } catch (Throwable $_fatal) {
            $summary['total_unique_employees'] = count($employees);
            $summary['batches_held'] = count($heldBatches);
            $summary['batches_failed'] = max(1, count($failedBatches));
            return $this->finish($summary, [
                'status' => $summary['batches_submitted'] > 0 ? 'partially_completed' : 'failed',
                'errors' => max(1, $summary['errors']),
                'reason' => 'Scheduler batch discovery or processing failed; inspect private server logs.',
            ]);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function finalStatus(array $summary): array
    {
        if ($summary['preview']) {
            if ($summary['events_pending'] > 0) {
                return ['status' => 'preview'];
            }
            if ($summary['records_skipped'] > 0 || $summary['records_held'] > 0 || $summary['batches_held'] > 0) {
                return ['status' => 'held'];
            }
            return ['status' => 'no_pending'];
        }
        if ($summary['batches_submitted'] > 0) {
            if ($summary['batches_failed'] > 0 || $summary['batches_held'] > 0 || $summary['errors'] > 0) {
                return ['status' => 'partially_completed'];
            }
            return ['status' => $summary['records_skipped'] > 0 ? 'completed_with_skips' : 'completed'];
        }
        if ($summary['batches_failed'] > 0) {
            return ['status' => 'failed'];
        }
        if ($summary['records_skipped'] > 0 || $summary['records_held'] > 0 || $summary['batches_held'] > 0) {
            return ['status' => 'held'];
        }
        return ['status' => 'no_pending'];
    }

    private function finish(array $summary, array $changes): array
    {
        $result = array_replace($summary, $changes);
        if (!$result['preview']) {
            ($this->logger)($result);
        }
        return $result;
    }
}
