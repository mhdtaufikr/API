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

    public function __construct(?callable $previewer = null, ?callable $sender = null, ?callable $logger = null)
    {
        $this->previewer = $previewer ?? static function (array $scope): array {
            $service = new LegacyManualSend(new LegacyAttendanceRepository(Database::open('reader')));
            return $service->preview(...$scope);
        };
        $this->sender = $sender ?? static function (array $scope, string $hash): array {
            $service = new LegacyManualSend(new LegacyAttendanceRepository(Database::open('worker')));
            return $service->send($scope, $hash);
        };
        $this->logger = $logger ?? static function (array $summary): void {
            (new ReceiverAudit(ROOT . '/storage/legacy-scheduler.jsonl'))->append($summary);
        };
    }

    public function run(bool $preview = false, ?DateTimeImmutable $now = null): array
    {
        if (env('INTEGRATION_STORAGE') !== 'legacy_app') {
            compat_throw(new RuntimeException('Legacy scheduler requires INTEGRATION_STORAGE=legacy_app.'));
        }

        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        $window = filter_var(env('TALENTA_SCHEDULE_WINDOW_MINUTES', '15'), FILTER_VALIDATE_INT);
        $days = filter_var(env('TALENTA_SCHEDULE_LOOKBACK_DAYS', '3'), FILTER_VALIDATE_INT);
        if ($window === false || $window < 1 || $window > 59 || $days === false || $days < 1 || $days > 7) {
            compat_throw(new RuntimeException('Invalid scheduler window or lookback configuration.'));
        }

        $slot = TalentaScheduledExport::slot($now, $window);
        $summary = [
            'mode' => 'legacy_app',
            'preview' => $preview,
            'timezone' => 'Asia/Jakarta',
            'schedule' => TalentaScheduledExport::TIMES,
            'slot' => $slot,
            'status' => 'preview',
            'events_pending' => 0,
            'batches_submitted' => 0,
            'batches_held' => 0,
            'flags_updated' => 0,
            'errors' => 0,
        ];

        if (!$preview && !enabled('TALENTA_SCHEDULER_ENABLED')) {
            return $this->finish($summary, ['status' => 'disabled', 'reason' => 'Scheduler disabled']);
        }
        if (!$preview && $slot === null) {
            return $this->finish($summary, ['status' => 'outside_schedule']);
        }

        $lock = null;
        if (!$preview) {
            $lock = fopen(ROOT . '/storage/legacy-scheduler.lock', 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->finish($summary, ['status' => 'already_running']);
            }
        }

        try {
            $from = $now->modify('-' . $days . ' days');
            $plans = [];
            $issues = [];
            for ($date = $from; $date <= $now; $date = $date->modify('+1 day')) {
                $day = $date->format('Y-m-d');
                $scope = [$day, $day, '', 'all'];
                try {
                    $plan = ($this->previewer)($scope);
                } catch (NoPendingLegacyAttendanceException $error) {
                    continue;
                }
                $summary['events_pending'] += count($plan['events'] ?? []);
                if (!empty($plan['issues'])) {
                    foreach ($plan['issues'] as $issue) {
                        $issues[] = $day . ': ' . $issue;
                    }
                    continue;
                }
                $plans[] = [$scope, $plan];
            }

            if ($issues) {
                return $this->finish($summary, [
                    'status' => 'held',
                    'batches_held' => count($issues),
                    'errors' => $preview ? 0 : 1,
                    'reason' => implode(' ', $issues),
                ]);
            }
            if (!$plans) {
                return $this->finish($summary, ['status' => 'no_pending']);
            }
            if ($preview) {
                return $summary;
            }

            $ledgerIds = [];
            $lastHttp = null;
            foreach ($plans as [$scope, $plan]) {
                $result = ($this->sender)($scope, (string) $plan['hash']);
                $accepted = ($result['status'] ?? '') === 'submitted_pending_reconciliation';
                $flagUpdated = ($result['flag_updated'] ?? false) === true;
                $lastHttp = $result['http'] ?? null;
                if (isset($result['id']) && is_string($result['id'])) {
                    $ledgerIds[] = $result['id'];
                }
                if (!$accepted || !$flagUpdated) {
                    $summary['errors']++;
                    break;
                }
                $summary['batches_submitted']++;
                $summary['flags_updated'] += count($plan['rows'] ?? []);
            }
            return $this->finish($summary, [
                'status' => $summary['errors'] ? 'completed_with_errors' : 'completed',
                'http' => $lastHttp,
                'ledger_ids' => $ledgerIds,
            ]);
        } catch (Throwable $error) {
            return $this->finish($summary, [
                'status' => 'completed_with_errors',
                'errors' => 1,
                'reason' => $error->getMessage(),
            ]);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
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
