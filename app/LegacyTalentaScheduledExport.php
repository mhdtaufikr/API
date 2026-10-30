<?php

declare(strict_types=1);

final class LegacyTalentaScheduledExport
{
    public const DEFAULT_EMPLOYEE_BATCH_SIZE = 100;

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
        $employeeBatch = filter_var(env('TALENTA_SCHEDULE_EMPLOYEE_BATCH_SIZE', (string) self::DEFAULT_EMPLOYEE_BATCH_SIZE), FILTER_VALIDATE_INT);
        $rowBatch = filter_var(env('TALENTA_SCHEDULE_ROW_BATCH_SIZE', '500'), FILTER_VALIDATE_INT);
        $maxSubmissions = filter_var(env('TALENTA_SCHEDULE_MAX_SUBMISSIONS', '4'), FILTER_VALIDATE_INT);
        if ($window === false || $window < 1 || $window > 59
            || $days === false || $days < 1 || $days > 7
            || $employeeBatch === false || $employeeBatch < 1 || $employeeBatch > 100
            || $rowBatch === false || $rowBatch < 1 || $rowBatch > 1000
            || $maxSubmissions === false || $maxSubmissions < 1 || $maxSubmissions > 4) {
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
            'max_submissions_per_run' => $maxSubmissions,
            'total_pending_records' => 0,
            'total_unique_employees' => 0,
            'batches_created' => 0,
            'batches_submitted' => 0,
            'batches_failed' => 0,
            'batches_deferred' => 0,
            'batches_held' => 0,
            'flags_updated' => 0,
            'records_skipped' => 0,
            'records_held' => 0,
            'events_pending' => 0,
            'events_skipped' => 0,
            'cards_skipped' => 0,
            'hold_reasons' => [],
            'errors' => 0,
            'dispatch_stopped' => false,
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
                } catch (Throwable $previewError) {
                    $heldBatches[$batchKey] = true;
                    self::addHoldReason($summary, $previewError->getMessage());
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
                    foreach ($plan['issues'] as $issue) {
                        self::addHoldReason($summary, (string) $issue);
                    }
                    if (!$preview) {
                        $summary['errors']++;
                    }
                    continue;
                }
                if (empty($plan['events']) || $preview) {
                    continue;
                }
                if ($summary['batches_submitted'] >= $maxSubmissions) {
                    $summary['dispatch_stopped'] = true;
                    break;
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
                } elseif ($resultStatus === 'deferred_retryable') {
                    $summary['batches_deferred']++;
                    $heldBatches[$batchKey] = true;
                    $summary['dispatch_stopped'] = true;
                    break;
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
            if (!$preview && $summary['total_pending_records'] > 0 && $summary['batches_submitted'] === 0 && $summary['errors'] === 0 && $summary['batches_deferred'] === 0) {
                $changes['errors'] = 1;
            }
            if ($summary['dispatch_stopped']) {
                $changes['reason'] = $summary['batches_deferred'] > 0
                    ? 'Talenta sedang memproses export/import lain; batch tersisa ditunda hingga run berikutnya.'
                    : 'Batas aman submission per run tercapai; batch tersisa tetap pending untuk run berikutnya.';
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

    private static function addHoldReason(array &$summary, string $message): void
    {
        $reason = self::holdReason($message);
        $summary['hold_reasons'][$reason] = ($summary['hold_reasons'][$reason] ?? 0) + 1;
    }

    private static function holdReason(string $message): string
    {
        if (preg_match('/Validasi kartu .* gagal \(HTTP ([0-9]{1,3})(?:, cURL ([0-9]{1,3}))?\)/', $message, $match)) {
            return 'identity_validation_http_' . $match[1] . (isset($match[2]) ? '_curl_' . $match[2] : '');
        }
        if (str_contains($message, 'Validasi identitas belum selesai')) {
            return 'identity_validation_budget';
        }
        if (str_contains($message, 'Cache identitas tidak tersedia')) {
            return 'identity_cache_unavailable';
        }
        if (str_contains($message, 'Kredensial Mekari belum valid')) {
            return 'mekari_credentials_invalid';
        }
        if (str_contains($message, 'cURL/API host Mekari belum sesuai')) {
            return 'mekari_runtime_invalid';
        }
        if (str_contains($message, 'Pengiriman belum diaktifkan')) {
            return 'live_write_gates_not_ready';
        }
        if (str_contains($message, 'Profil CSV')) {
            return 'csv_profile_invalid';
        }
        if (str_contains($message, 'Invalid scheduled scope')) {
            return 'scheduled_scope_invalid';
        }
        return 'batch_preview_error';
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
            if ($summary['batches_failed'] > 0 || $summary['batches_held'] > 0 || $summary['errors'] > 0 || $summary['dispatch_stopped']) {
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
