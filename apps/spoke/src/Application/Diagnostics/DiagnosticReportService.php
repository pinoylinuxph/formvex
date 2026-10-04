<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Diagnostics;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Application\Backup\ScheduledBackupService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Application\InstallationSettings\SmtpDiagnosticsService;
use Formvex\Spoke\Application\Release\ReleaseCheckService;
use Formvex\Spoke\Application\Storage\StorageSettingsService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Diagnostics\Contract\DiagnosticReportRepository;
use Formvex\Spoke\Domain\Diagnostics\Contract\DiagnosticReportStore;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportFailure;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportGeneration;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportMetadata;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\Contract\PrivateStorage;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Overview\Contract\OverviewSummaryReader;
use Formvex\Spoke\Domain\Retention\Contract\RetentionRepository;
use Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository;
use Throwable;

final readonly class DiagnosticReportService
{
    private const EXPIRY_MINUTES = 15;

    private const COOLDOWN_MINUTES = 5;

    private const MAX_REPORTS = 30;

    private const CLEANUP_LIMIT = 10;

    public function __construct(
        private PrivateStorage $privateStorage,
        private SpokeStorageResolver $storageResolver,
        private DiagnosticReportRepository $repository,
        private DiagnosticReportStore $store,
        private OverviewSummaryReader $overviewReader,
        private StorageSettingsService $storageSettingsService,
        private InstallationSettingsService $installationSettingsService,
        private SmtpDiagnosticsService $smtpDiagnosticsService,
        private SchedulerHealthRepository $schedulerHealthRepository,
        private RetentionRepository $retentionRepository,
        private BackupService $backupService,
        private ScheduledBackupService $scheduledBackupService,
        private RecoveryHoldStore $recoveryHoldStore,
        private ReleaseCheckService $releaseCheckService,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function latest(string $applicationRoot): ?DiagnosticReportMetadata
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $this->cleanup($paths, $this->now(), 'system');

        return $this->repository->latest($paths);
    }

    public function generate(string $applicationRoot, string $actor): DiagnosticReportGeneration
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $lock = $this->privateStorage->acquireLock($paths);
        $now = $this->now();
        $report = null;
        $storageKey = null;
        try {
            $this->cleanupExpired($paths, $now, $actor);
            $latest = $this->repository->latest($paths);
            if ($latest !== null && $latest->generatedAt->getTimestamp() > $now->getTimestamp() - (self::COOLDOWN_MINUTES * 60)) {
                try {
                    return new DiagnosticReportGeneration(
                        $this->store->read($paths, $latest->storageKey),
                        false,
                        $latest->generatedAt->modify('+' . self::COOLDOWN_MINUTES . ' minutes'),
                    );
                } catch (DiagnosticReportFailure) {
                    $this->repository->remove($paths, $latest->publicId, $actor, 'storage_missing', $now);
                }
            }

            $publicId = $this->identifierGenerator->uuidV7($now);
            $report = $this->buildReport($paths, $publicId, $now);
            $storageKey = 'diagnostic-report-' . $publicId . '.json';
            $size = $this->store->write($paths, $report, $storageKey);
            $metadata = new DiagnosticReportMetadata(
                $publicId,
                $actor,
                $storageKey,
                $report->generatedAt,
                $report->expiresAt,
                $size,
            );
            try {
                $this->repository->publish($paths, $metadata, $now);
            } catch (Throwable $failure) {
                $this->store->delete($paths, $storageKey);
                throw new DiagnosticReportFailure('report_publish_failed', 'The diagnostic report could not be published safely.', $failure);
            }
            $this->rotate($paths, $actor, $now);

            return new DiagnosticReportGeneration($report, true, $report->expiresAt);
        } catch (DiagnosticReportFailure $failure) {
            if ($storageKey !== null && $report !== null) {
                try {
                    $this->store->delete($paths, $storageKey);
                } catch (Throwable) {
                    // The database remains the authoritative report publication state.
                }
            }
            try {
                $this->repository->recordAudit($paths, 'spoke.diagnostic_report.generation_failed', $failure->failureCode, $actor, $report?->publicId, $now);
            } catch (Throwable) {
                // A report failure must not expose storage or database details to the portal.
            }
            throw $failure;
        } catch (Throwable $failure) {
            if ($storageKey !== null) {
                try {
                    $this->store->delete($paths, $storageKey);
                } catch (Throwable) {
                    // Preserve the safe failure response.
                }
            }
            try {
                $this->repository->recordAudit($paths, 'spoke.diagnostic_report.generation_failed', 'report_generation_failed', $actor, $report?->publicId, $now);
            } catch (Throwable) {
                // Preserve the safe failure response.
            }
            throw new DiagnosticReportFailure('report_generation_failed', 'The diagnostic report could not be completed safely.', $failure);
        } finally {
            $lock->release();
        }
    }

    public function read(string $applicationRoot, string $publicId, string $actor, string $action): DiagnosticReport
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $this->cleanup($paths, $this->now(), 'system');
        $metadata = $this->repository->find($paths, $publicId);
        if ($metadata === null || $metadata->isExpired($this->now())) {
            $this->auditBestEffort($paths, 'spoke.diagnostic_report.' . $action, 'report_unavailable', $actor, $publicId);
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available. Generate a new report from Diagnostics.');
        }
        try {
            $report = $this->store->read($paths, $metadata->storageKey);
            $this->repository->recordAudit($paths, 'spoke.diagnostic_report.' . $action, 'success', $actor, $publicId, $this->now());

            return $report;
        } catch (Throwable $failure) {
            $this->auditBestEffort($paths, 'spoke.diagnostic_report.' . $action, 'report_unavailable', $actor, $publicId);
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available. Generate a new report from Diagnostics.', $failure);
        }
    }

    public function plainText(DiagnosticReport $report): string
    {
        $lines = [
            'Redacted diagnostic report',
            'Generated: ' . $this->displayTimestamp($report->generatedAt),
            'Expires: ' . $this->displayTimestamp($report->expiresAt),
            '',
        ];
        foreach ($report->sections as $section) {
            $lines[] = '[' . $section['label'] . ']';
            $lines[] = 'Status: ' . $section['status'];
            foreach ($section['entries'] as $entry) {
                $lines[] = $entry['label'] . ': ' . $entry['value'];
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function buildReport(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): DiagnosticReport
    {
        $sections = [];
        $errors = [];
        $sections[] = $this->source($errors, 'installation', 'Installation', function () use ($paths): array {
            $marker = $this->privateStorage->readMarker($paths);

            return [
                'key' => 'installation',
                'label' => 'Installation',
                'status' => $marker === null ? 'Unavailable' : 'Available',
                'entries' => [
                    $this->entry('product', 'Product', 'Local Spoke'),
                    $this->entry('schema', 'Schema version', $marker === null ? 'Unavailable' : $marker->schemaVersion),
                    $this->entry('php', 'PHP runtime', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION),
                    $this->entry('capabilities', 'Runtime capabilities', $this->capabilities()),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'health', 'Health', function () use ($paths): array {
            $hold = $this->recoveryHoldStore->current($paths);
            $retention = $this->retentionRepository->status($paths);

            return [
                'key' => 'health',
                'label' => 'Health',
                'status' => 'Available',
                'entries' => [
                    $this->entry('recovery', 'Recovery state', $hold === null ? 'Clear' : 'Action required'),
                    $this->entry('retention', 'Retention cleanup', $retention->lastStatus ?? 'Not run'),
                    $this->entry('retention_last_run', 'Last retention run', $retention->lastRunAt ?? 'Not available'),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'delivery', 'Delivery', function () use ($paths): array {
            $submissions = $this->overviewReader->submissions($paths);
            $delivery = $this->overviewReader->delivery($paths);

            return [
                'key' => 'delivery',
                'label' => 'Delivery',
                'status' => 'Available',
                'entries' => [
                    $this->entry('submissions', 'Submissions', (string) $submissions->total),
                    $this->entry('unhandled', 'Unhandled', (string) $submissions->unhandled),
                    $this->entry('suspected_spam', 'Suspected spam', (string) $submissions->suspectedSpam),
                    $this->entry('queued', 'Queued', (string) $delivery->queued),
                    $this->entry('processing', 'Processing', (string) $delivery->processing),
                    $this->entry('sent', 'Sent', (string) $delivery->sent),
                    $this->entry('failed', 'Failed or uncertain', (string) $delivery->attention()),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'storage', 'Storage', function () use ($paths): array {
            $snapshot = $this->storageSettingsService->snapshot($paths->applicationRoot);

            return [
                'key' => 'storage',
                'label' => 'Storage',
                'status' => $snapshot->usage->state->value === 'unavailable' ? 'Unavailable' : 'Available',
                'entries' => [
                    $this->entry('state', 'State', $snapshot->usage->state->value),
                    $this->entry('usage', 'Usage', $snapshot->usage->percent . '%'),
                    $this->entry('live_bytes', 'Live data', (string) $snapshot->usage->liveBytes . ' bytes'),
                    $this->entry('allowance_bytes', 'Allowance', (string) $snapshot->usage->allowanceBytes . ' bytes'),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'scheduler', 'Scheduler', function () use ($paths, $now): array {
            $health = $this->schedulerHealthRepository->status($paths, $now);
            $entries = [$this->entry('overall', 'Overall status', $health->status)];
            foreach ($health->jobs as $job) {
                $entries[] = $this->entry($job->key, $job->label, $job->status . ' (' . $job->schedule . ')');
                $entries[] = $this->entry($job->key . '_last_success', $job->label . ' last success', $job->lastSuccessfulAt ?? 'Not available');
            }

            return ['key' => 'scheduler', 'label' => 'Scheduler', 'status' => ucfirst($health->status), 'entries' => $entries];
        });
        $sections[] = $this->source($errors, 'backups', 'Backups', function () use ($paths): array {
            $settings = $this->scheduledBackupService->settings($paths->applicationRoot);
            $manual = count(array_filter($this->backupService->list($paths->applicationRoot), static fn ($archive): bool => $archive->isDownloadable()));
            $scheduled = count(array_filter($this->scheduledBackupService->list($paths->applicationRoot), static fn ($archive): bool => $archive->isDownloadable()));

            return [
                'key' => 'backups',
                'label' => 'Backups',
                'status' => 'Available',
                'entries' => [
                    $this->entry('schedule', 'Scheduled backups', $settings->enabled ? $settings->scheduleLabel() : 'Disabled'),
                    $this->entry('verified_manual', 'Verified manual copies', (string) $manual),
                    $this->entry('verified_scheduled', 'Verified scheduled copies', (string) $scheduled),
                    $this->entry('last_status', 'Last scheduled result', $settings->lastStatus ?? 'Not run'),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'smtp', 'Email delivery', function () use ($paths): array {
            $snapshot = $this->installationSettingsService->snapshot($paths->applicationRoot);
            $view = $this->smtpDiagnosticsService->fromSnapshot($snapshot);

            return [
                'key' => 'smtp',
                'label' => 'Email delivery',
                'status' => $view->label,
                'entries' => [
                    $this->entry('status', 'Status', $view->label),
                    $this->entry('configuration', 'Configuration', $view->configurationStatus),
                    $this->entry('test_stage', 'Last test stage', $view->failureStage ?? 'Not applicable'),
                    $this->entry('tested_at', 'Last tested', $view->testedAt?->format('Y-m-d\TH:i:s\Z') ?? 'Not tested'),
                ],
            ];
        });
        $sections[] = $this->source($errors, 'release', 'Release', function () use ($paths, $now): array {
            $settings = $this->releaseCheckService->settings($paths->applicationRoot);
            $state = $this->releaseCheckService->state($paths->applicationRoot);

            return [
                'key' => 'release',
                'label' => 'Release',
                'status' => 'Available',
                'entries' => [
                    $this->entry('checks', 'Release checks', $settings->enabled ? 'Enabled' : 'Disabled'),
                    $this->entry('status', 'Current status', $state->displayStatus($now)),
                    $this->entry('current', 'Current version', $this->safeVersion($state->currentVersion)),
                    $this->entry('available', 'Available version', $this->safeVersion($state->availableVersion)),
                    $this->entry('last_check', 'Last checked', $state->lastAttemptAt?->format('Y-m-d\TH:i:s\Z') ?? 'Not checked'),
                ],
            ];
        });
        $sections[] = [
            'key' => 'errors',
            'label' => 'Unavailable sources',
            'status' => $errors === [] ? 'None' : 'Review required',
            'entries' => [$this->entry('source_errors', 'Source status', $errors === [] ? 'No unavailable sources' : implode(', ', $errors))],
        ];

        return new DiagnosticReport($publicId, $now, $now->modify('+' . self::EXPIRY_MINUTES . ' minutes'), $sections);
    }

    /**
     * @param list<string> $errors
     * @param callable(): mixed $source
     * @return array{key: string, label: string, status: string, entries: list<array{key: string, label: string, value: string}>}
     */
    private function source(array &$errors, string $key, string $label, callable $source): array
    {
        try {
            $result = $source();
            if (!is_array($result)) {
                throw new DiagnosticReportFailure('report_source_unavailable', 'The report source is unavailable.');
            }

            return $this->normalizeSection($result);
        } catch (Throwable) {
            $errors[] = $key . '_unavailable';

            return [
                'key' => $key,
                'label' => $label,
                'status' => 'Unavailable',
                'entries' => [$this->entry('availability', 'Status', 'Unavailable')],
            ];
        }
    }

    /**
     * @param array<int|string, mixed> $section
     * @return array{key: string, label: string, status: string, entries: list<array{key: string, label: string, value: string}>}
     */
    private function normalizeSection(array $section): array
    {
        if (!is_string($section['key'] ?? null) || !is_string($section['label'] ?? null) || !is_string($section['status'] ?? null) || !is_array($section['entries'] ?? null)) {
            throw new DiagnosticReportFailure('report_source_unavailable', 'The report source is unavailable.');
        }
        $entries = array_values(array_map(fn (mixed $entry): array => $this->normalizeEntry($entry), $section['entries']));

        return ['key' => $section['key'], 'label' => $section['label'], 'status' => $section['status'], 'entries' => $entries];
    }

    /** @return array{key: string, label: string, value: string} */
    private function normalizeEntry(mixed $entry): array
    {
        if (!is_array($entry) || !is_string($entry['key'] ?? null) || !is_string($entry['label'] ?? null) || !is_string($entry['value'] ?? null)) {
            throw new DiagnosticReportFailure('report_source_unavailable', 'The report source is unavailable.');
        }

        return ['key' => $entry['key'], 'label' => $entry['label'], 'value' => $entry['value']];
    }

    /** @return array{key: string, label: string, value: string} */
    private function entry(string $key, string $label, string $value): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $this->safeValue($value)];
    }

    private function safeValue(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        $value = preg_replace('/(?:[A-Za-z]:)?(?:\\\\|\/)[^\s]+/', '[redacted path]', $value) ?? $value;
        $value = preg_replace('/\b(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}\b/', '[redacted host]', $value) ?? $value;

        return substr($value, 0, 256);
    }

    private function safeVersion(?string $version): string
    {
        return $version !== null && preg_match('/\A(?:unversioned|(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))\z/', $version) === 1 ? $version : 'Unavailable';
    }

    private function capabilities(): string
    {
        $capabilities = ['SQLite'];
        foreach (['openssl', 'mbstring', 'intl'] as $extension) {
            if (extension_loaded($extension)) {
                $capabilities[] = strtoupper($extension);
            }
        }

        return implode(', ', $capabilities);
    }

    private function rotate(PrivateStoragePaths $paths, string $actor, DateTimeImmutable $now): void
    {
        $reports = $this->repository->available($paths);
        while (count($reports) > self::MAX_REPORTS) {
            $oldest = array_shift($reports);
            try {
                $this->store->delete($paths, $oldest->storageKey);
                $this->repository->remove($paths, $oldest->publicId, $actor, 'rotation', $now);
            } catch (Throwable) {
                $this->auditBestEffort($paths, 'spoke.diagnostic_report.expiry_cleanup', 'rotation_failed', $actor, $oldest->publicId);
                break;
            }
        }
    }

    private function cleanup(PrivateStoragePaths $paths, DateTimeImmutable $now, string $actor): void
    {
        try {
            $lock = $this->privateStorage->acquireLock($paths);
        } catch (Throwable) {
            return;
        }
        try {
            $this->cleanupExpired($paths, $now, $actor);
        } finally {
            $lock->release();
        }
    }

    private function cleanupExpired(PrivateStoragePaths $paths, DateTimeImmutable $now, string $actor): void
    {
        foreach ($this->repository->expired($paths, $now, self::CLEANUP_LIMIT) as $metadata) {
            try {
                $this->store->delete($paths, $metadata->storageKey);
                $this->repository->remove($paths, $metadata->publicId, $actor, 'expiry_cleanup', $now);
            } catch (Throwable) {
                $this->auditBestEffort($paths, 'spoke.diagnostic_report.expiry_cleanup', 'cleanup_failed', $actor, $metadata->publicId);
            }
        }
    }

    private function auditBestEffort(PrivateStoragePaths $paths, string $event, string $outcome, string $actor, ?string $publicId): void
    {
        try {
            $this->repository->recordAudit($paths, $event, $outcome, $actor, $publicId, $this->now());
        } catch (Throwable) {
            // Audit failure is intentionally not exposed through the portal.
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function displayTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C');
    }
}
