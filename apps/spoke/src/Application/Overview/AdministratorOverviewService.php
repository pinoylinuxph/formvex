<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

use DateTimeZone;
use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Application\Storage\StorageSettingsService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use Formvex\Spoke\Domain\Overview\Contract\OverviewSummaryReader;
use Formvex\Spoke\Domain\Overview\DeliveryOverviewCounts;
use Formvex\Spoke\Domain\Overview\FormOverviewCounts;
use Formvex\Spoke\Domain\Overview\SubmissionOverviewCounts;
use Formvex\Spoke\Domain\Scheduler\SchedulerHealth;
use Formvex\Spoke\Domain\Scheduler\SchedulerJobStatus;
use Formvex\Spoke\Domain\Storage\StorageSnapshot;
use Formvex\Spoke\Domain\Storage\StorageState;
use Throwable;

final readonly class AdministratorOverviewService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private OverviewSummaryReader $summaryReader,
        private StorageSettingsService $storageSettingsService,
        private InstallationSettingsService $installationSettingsService,
        private \Formvex\Spoke\Domain\Retention\Contract\RetentionRepository $retentionRepository,
        private \Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository $schedulerHealthRepository,
        private BackupService $backupService,
        private RecoveryHoldStore $recoveryHoldStore,
        private Clock $clock,
    ) {
    }

    public function summary(string $applicationRoot): AdministratorOverview
    {
        try {
            $paths = $this->storageResolver->resolve($applicationRoot);
        } catch (Throwable) {
            return $this->unavailableSummary();
        }

        $forms = $this->readForms($paths);
        $submissions = $this->readSubmissions($paths);
        $delivery = $this->readDelivery($paths);
        $storage = $this->readStorage($applicationRoot);
        $settings = $this->readSettings($applicationRoot);
        $scheduler = $this->readScheduler($paths);
        $retention = $this->readRetention($paths);
        [$backupCount, $recoveryAvailable, $recoveryHold] = $this->readMaintenance($applicationRoot, $paths);

        $warnings = [];
        $cards = [
            $this->formsCard($forms, $warnings),
            $this->submissionsCard($submissions, $warnings),
            $this->deliveryCard($delivery, $warnings),
            $this->storageCard($storage, $warnings),
            $this->schedulerCard($scheduler, $warnings),
        ];
        $systemStatus = $this->systemStatus($settings, $scheduler, $retention, $backupCount, $recoveryAvailable, $recoveryHold, $warnings);

        return new AdministratorOverview($cards, $warnings, $systemStatus, 'Current local status snapshot');
    }

    private function readForms(PrivateStoragePaths $paths): ?FormOverviewCounts
    {
        try {
            return $this->summaryReader->forms($paths);
        } catch (Throwable) {
            return null;
        }
    }

    private function readSubmissions(PrivateStoragePaths $paths): ?SubmissionOverviewCounts
    {
        try {
            return $this->summaryReader->submissions($paths);
        } catch (Throwable) {
            return null;
        }
    }

    private function readDelivery(PrivateStoragePaths $paths): ?DeliveryOverviewCounts
    {
        try {
            return $this->summaryReader->delivery($paths);
        } catch (Throwable) {
            return null;
        }
    }

    private function readStorage(string $applicationRoot): ?StorageSnapshot
    {
        try {
            return $this->storageSettingsService->snapshot($applicationRoot);
        } catch (Throwable) {
            return null;
        }
    }

    private function readSettings(string $applicationRoot): ?SettingsSnapshot
    {
        try {
            return $this->installationSettingsService->snapshot($applicationRoot);
        } catch (Throwable) {
            return null;
        }
    }

    private function readScheduler(PrivateStoragePaths $paths): ?SchedulerHealth
    {
        try {
            return $this->schedulerHealthRepository->status($paths, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
        } catch (Throwable) {
            return null;
        }
    }

    private function readRetention(PrivateStoragePaths $paths): ?\Formvex\Spoke\Domain\Retention\RetentionStatus
    {
        try {
            return $this->retentionRepository->status($paths);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: ?int, 1: bool, 2: bool} */
    private function readMaintenance(string $applicationRoot, PrivateStoragePaths $paths): array
    {
        try {
            $backups = $this->backupService->list($applicationRoot);
            $recoveryHold = $this->recoveryHoldStore->current($paths) !== null;

            return [count($backups), true, $recoveryHold];
        } catch (Throwable) {
            return [null, false, false];
        }
    }

    /** @param list<OverviewWarning> $warnings */
    private function formsCard(?FormOverviewCounts $counts, array &$warnings): OverviewCard
    {
        if ($counts === null) {
            $this->warning($warnings, 'Forms status unavailable', 'The form summary could not be read. Open Forms and refresh after checking the local database.', 'warning', 'spoke_admin_forms', 'Open Forms');

            return $this->unavailableCard('Forms', 'Open Forms', 'The current form inventory could not be read.', 'spoke_admin_forms');
        }

        if ($counts->needsAttention > 0) {
            $this->warning($warnings, 'Forms need attention', sprintf('%d form(s) have a disabled active version or incomplete current qualification evidence.', $counts->needsAttention), 'warning', 'spoke_admin_forms', 'Review Forms');
        }

        $ready = max(0, $counts->active - $counts->needsAttention);
        $other = max(0, $counts->total - $ready - $counts->needsAttention);

        return new OverviewCard(
            'Forms',
            $counts->active . ' active',
            $counts->needsAttention > 0 ? 'Attention' : 'Ready',
            $counts->needsAttention > 0 ? 'warning' : 'success',
            $counts->total . ' configured form(s) in the local inventory.',
            'spoke_admin_forms',
            'Open Forms',
            [
                new OverviewStat('Active', (string) $counts->active, 'Currently available for intake.', 'success'),
                new OverviewStat('Needs attention', (string) $counts->needsAttention, 'Disabled or not currently qualified.', $counts->needsAttention > 0 ? 'warning' : 'success'),
            ],
            $this->segments([
                ['Ready', $ready, 'success'],
                ['Attention', $counts->needsAttention, 'warning'],
                ['Draft or unpublished', $other, 'neutral'],
            ], max(1, $counts->total)),
        );
    }

    /** @param list<OverviewWarning> $warnings */
    private function submissionsCard(?SubmissionOverviewCounts $counts, array &$warnings): OverviewCard
    {
        if ($counts === null) {
            $this->warning($warnings, 'Submission status unavailable', 'The submission summary could not be read. Open Submissions and refresh after checking the local database.', 'warning', 'spoke_admin_submissions', 'Open Submissions');

            return $this->unavailableCard('Submissions', 'Open Submissions', 'The current submission workload could not be read.', 'spoke_admin_submissions');
        }

        if ($counts->unhandled > 0 || $counts->suspectedSpam > 0) {
            $this->warning($warnings, 'Submissions need review', sprintf('%d unhandled submission(s) and %d suspected spam record(s) are available for review.', $counts->unhandled, $counts->suspectedSpam), 'warning', 'spoke_admin_submissions', 'Review Submissions');
        }

        return new OverviewCard(
            'Submissions',
            (string) $counts->total,
            $counts->unhandled > 0 ? 'Review' : 'Clear',
            $counts->unhandled > 0 ? 'warning' : 'success',
            'Active submission records excluding the Trash.',
            'spoke_admin_submissions',
            'Open Submissions',
            [
                new OverviewStat('Unhandled', (string) $counts->unhandled, 'Accepted records not marked handled.', $counts->unhandled > 0 ? 'warning' : 'success'),
                new OverviewStat('Suspected spam', (string) $counts->suspectedSpam, 'Records classified for review.', $counts->suspectedSpam > 0 ? 'warning' : 'success'),
            ],
            $this->segments([
                ['Needs review', $counts->unhandled, 'warning'],
                ['Handled', max(0, $counts->total - $counts->unhandled), 'success'],
            ], max(1, $counts->total)),
        );
    }

    /** @param list<OverviewWarning> $warnings */
    private function deliveryCard(?DeliveryOverviewCounts $counts, array &$warnings): OverviewCard
    {
        if ($counts === null) {
            $this->warning($warnings, 'Delivery status unavailable', 'The delivery summary could not be read. Open Delivery and refresh after checking the local database.', 'warning', 'spoke_admin_delivery', 'Open Delivery');

            return $this->unavailableCard('Delivery', 'Open Delivery', 'The current delivery workload could not be read.', 'spoke_admin_delivery');
        }

        if ($counts->attention() > 0) {
            $this->warning($warnings, 'Delivery attention required', sprintf('%d failed or uncertain delivery record(s) require review.', $counts->attention()), 'warning', 'spoke_admin_delivery', 'Review Delivery');
        }

        return new OverviewCard(
            'Delivery',
            (string) $counts->total,
            $counts->attention() > 0 ? 'Attention' : 'Clear',
            $counts->attention() > 0 ? 'warning' : 'success',
            'Current delivery jobs, including queued and completed work.',
            'spoke_admin_delivery',
            'Open Delivery',
            [
                new OverviewStat('Queued', (string) $counts->queued, 'Waiting for the delivery worker.', $counts->queued > 0 ? 'information' : 'neutral'),
                new OverviewStat('Sent', (string) $counts->sent, 'Accepted by the configured SMTP server.', 'success'),
                new OverviewStat('Failed / uncertain', (string) $counts->attention(), 'Requires delivery review.', $counts->attention() > 0 ? 'warning' : 'success'),
            ],
            $this->segments([
                ['Queued', $counts->queued, 'information'],
                ['Processing', $counts->processing, 'neutral'],
                ['Sent', $counts->sent, 'success'],
                ['Failed or uncertain', $counts->attention(), 'warning'],
            ], max(1, $counts->total)),
        );
    }

    /** @param list<OverviewWarning> $warnings */
    private function storageCard(?StorageSnapshot $snapshot, array &$warnings): OverviewCard
    {
        if ($snapshot === null || $snapshot->usage->state === StorageState::UNAVAILABLE) {
            $this->warning($warnings, 'Storage status unavailable', 'Storage usage could not be measured. Open Settings and verify the private storage before accepting more data.', 'warning', 'spoke_admin_settings', 'Open Settings');

            return $this->unavailableCard('Storage', 'Open Settings', 'The private storage allowance could not be measured.', 'spoke_admin_settings');
        }

        $usage = $snapshot->usage;
        $severity = match ($usage->state) {
            StorageState::NORMAL => 'success',
            StorageState::WARNING => 'warning',
            StorageState::CRITICAL, StorageState::FULL => 'danger',
            StorageState::UNAVAILABLE => 'warning',
        };
        $status = match ($usage->state) {
            StorageState::NORMAL => 'Normal',
            StorageState::WARNING => 'Warning',
            StorageState::CRITICAL => 'Critical',
            StorageState::FULL => 'Full',
            StorageState::UNAVAILABLE => 'Unavailable',
        };
        if ($usage->state !== StorageState::NORMAL) {
            $this->warning($warnings, 'Storage capacity needs attention', sprintf('Storage is at %d%% of the configured allowance. Review storage settings before accepting more submissions.', $usage->percent), $severity === 'danger' ? 'critical' : 'warning', 'spoke_admin_settings', 'Review Storage Settings');
        }

        return new OverviewCard(
            'Storage',
            $this->bytes($usage->liveBytes) . ' / ' . $this->bytes($usage->allowanceBytes),
            $status,
            $severity,
            sprintf('%d%% of the configured local storage allowance is in use.', $usage->percent),
            'spoke_admin_settings',
            'Open Settings',
            [
                new OverviewStat('Used', $this->bytes($usage->liveBytes), 'Database, logs, diagnostics, and active exports.', $severity),
                new OverviewStat('Allowance', $this->bytes($usage->allowanceBytes), 'Configured maximum for local storage.', 'neutral'),
            ],
            [],
            min($usage->liveBytes, $usage->allowanceBytes),
            $usage->allowanceBytes,
            sprintf('Storage usage: %d%%', $usage->percent),
        );
    }

    /** @param list<OverviewWarning> $warnings */
    private function schedulerCard(?SchedulerHealth $health, array &$warnings): OverviewCard
    {
        if ($health === null) {
            $this->warning($warnings, 'Scheduler status unavailable', 'The scheduler heartbeat could not be read. Open Diagnostics and check the local scheduler status.', 'warning', 'spoke_admin_diagnostics', 'Open Diagnostics');

            return $this->unavailableCard('Scheduler', 'Open Diagnostics', 'Scheduled-task health could not be read.', 'spoke_admin_diagnostics');
        }

        if ($health->status !== 'healthy') {
            $this->warning($warnings, 'Scheduler attention required', $health->message, $health->severity, 'spoke_admin_diagnostics', 'Open Diagnostics');
        }

        $stats = [];
        foreach ($health->jobs as $job) {
            $stats[] = new OverviewStat($job->label, $this->statusLabel($job->status), $this->schedulerDetail($job), $job->severity);
        }

        return new OverviewCard(
            'Scheduler',
            $health->status === 'healthy' ? 'Healthy' : 'Attention',
            $health->status === 'healthy' ? 'Healthy' : 'Review',
            $health->severity,
            'Local worker heartbeats are the only scheduler state available to the application.',
            'spoke_admin_diagnostics',
            'Open Diagnostics',
            $stats,
        );
    }

    /**
     * @param list<OverviewWarning> $warnings
     * @return list<OverviewStatusRow>
     */
    private function systemStatus(?SettingsSnapshot $settings, ?SchedulerHealth $scheduler, ?\Formvex\Spoke\Domain\Retention\RetentionStatus $retention, ?int $backupCount, bool $recoveryAvailable, bool $recoveryHold, array &$warnings): array
    {
        $rows = [];
        if ($settings === null) {
            $rows[] = new OverviewStatusRow('SMTP readiness', 'Unavailable', 'Settings could not be read. No delivery readiness claim is made.', 'warning', 'spoke_admin_settings', 'Open Settings');
            $this->warning($warnings, 'SMTP readiness unavailable', 'The SMTP configuration state could not be read. Open Settings and verify the saved configuration.', 'warning', 'spoke_admin_settings', 'Open Settings');
        } else {
            $smtpCurrent = $settings->smtpReady()
                && $settings->testState->status === SmtpTestStatus::PASSED
                && $settings->testState->testedRevision === $settings->settings->smtpConfigurationRevision;
            $smtpStatus = $smtpCurrent ? 'Ready and tested' : 'Needs setup or test';
            $smtpSeverity = $smtpCurrent ? 'success' : 'warning';
            $rows[] = new OverviewStatusRow('SMTP readiness', $smtpStatus, $smtpCurrent ? 'The saved SMTP settings passed the latest explicit test.' : 'Configure the SMTP settings and run a current explicit test before relying on delivery.', $smtpSeverity, 'spoke_admin_settings', 'Open Settings');
            if (!$smtpCurrent) {
                $this->warning($warnings, 'SMTP configuration needs attention', 'The saved SMTP configuration is incomplete or its latest test is not current. Open Settings before relying on delivery.', 'warning', 'spoke_admin_settings', 'Open Settings');
            }
        }

        if ($scheduler === null) {
            $rows[] = new OverviewStatusRow('Worker heartbeats', 'Unavailable', 'Scheduled-task state could not be read.', 'warning', 'spoke_admin_diagnostics', 'Open Diagnostics');
        } else {
            foreach ($scheduler->jobs as $job) {
                $rows[] = new OverviewStatusRow($job->label, $this->statusLabel($job->status), $this->schedulerDetail($job), $job->severity, 'spoke_admin_diagnostics', 'Open Diagnostics');
            }
        }

        if ($retention === null) {
            $rows[] = new OverviewStatusRow('Retention status', 'Unavailable', 'Retention history could not be read.', 'warning', 'spoke_admin_maintenance', 'Open Maintenance');
        } else {
            $retentionStatus = $retention->lastStatus === null ? 'Not run' : ucfirst(str_replace('_', ' ', $retention->lastStatus));
            $rows[] = new OverviewStatusRow('Retention status', $retentionStatus, $retention->lastSuccessAt === null ? 'No successful cleanup has been recorded.' : 'A successful cleanup has been recorded.', $retention->lastStatus === 'partial_failure' ? 'danger' : 'neutral', 'spoke_admin_maintenance', 'Open Maintenance');
        }

        if (!$recoveryAvailable) {
            $rows[] = new OverviewStatusRow('Backup inventory', 'Unavailable', 'Manual backup inventory could not be read.', 'warning', 'spoke_admin_maintenance', 'Open Maintenance');
        } else {
            $rows[] = new OverviewStatusRow('Backup inventory', $backupCount === 0 ? 'None yet' : $backupCount . ' available', 'Existing manual backup inventory only; scheduled backup status is not included here.', $backupCount === 0 ? 'neutral' : 'success', 'spoke_admin_maintenance', 'Open Maintenance');
        }

        $rows[] = new OverviewStatusRow('Recovery state', $recoveryHold ? 'Action required' : 'Clear', $recoveryHold ? 'The installation is in recovery hold. Open Maintenance before using recovery actions.' : 'No recovery hold is active.', $recoveryHold ? 'danger' : 'success', 'spoke_admin_maintenance', 'Open Maintenance');

        return $rows;
    }

    /** @param list<OverviewWarning> $warnings */
    private function warning(array &$warnings, string $title, string $message, string $severity, string $route, string $routeLabel): void
    {
        $warnings[] = new OverviewWarning($title, $message, $severity, $route, $routeLabel);
    }

    private function unavailableCard(string $label, string $routeLabel, string $summary, string $route): OverviewCard
    {
        return new OverviewCard($label, 'Unavailable', 'Unavailable', 'warning', $summary, $route, $routeLabel);
    }

    /**
     * @param list<array{0: string, 1: int, 2: string}> $items
     * @return list<OverviewSegment>
     */
    private function segments(array $items, int $total): array
    {
        return array_map(
            static fn (array $item): OverviewSegment => new OverviewSegment($item[0], $item[1], min(100, max(0, (int) round(($item[1] * 100) / $total))), $item[2]),
            $items,
        );
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'healthy' => 'Healthy',
            'stale' => 'Stale',
            'failed' => 'Failed',
            'not_confirmed' => 'Not confirmed',
            default => 'Unavailable',
        };
    }

    private function schedulerDetail(SchedulerJobStatus $job): string
    {
        return $job->lastSuccessfulAt === null ? 'No successful run recorded.' : 'Last success ' . $job->lastSuccessfulAt;
    }

    private function bytes(int $bytes): string
    {
        if ($bytes >= 1_000_000_000) {
            return number_format($bytes / 1_000_000_000, 2) . ' GB';
        }
        if ($bytes >= 1_000_000) {
            return number_format($bytes / 1_000_000, 1) . ' MB';
        }

        return number_format($bytes / 1_000, 0) . ' KB';
    }

    private function unavailableSummary(): AdministratorOverview
    {
        $warnings = [new OverviewWarning('Overview data unavailable', 'The local installation sources could not be read. Open Diagnostics or Maintenance and verify the private installation before relying on any status.', 'danger', 'spoke_admin_diagnostics', 'Open Diagnostics')];
        $cards = [
            $this->unavailableCard('Forms', 'Open Forms', 'The form inventory could not be read.', 'spoke_admin_forms'),
            $this->unavailableCard('Submissions', 'Open Submissions', 'The submission workload could not be read.', 'spoke_admin_submissions'),
            $this->unavailableCard('Delivery', 'Open Delivery', 'The delivery workload could not be read.', 'spoke_admin_delivery'),
            $this->unavailableCard('Storage', 'Open Settings', 'The private storage allowance could not be measured.', 'spoke_admin_settings'),
            $this->unavailableCard('Scheduler', 'Open Diagnostics', 'Scheduled-task health could not be read.', 'spoke_admin_diagnostics'),
        ];

        return new AdministratorOverview($cards, $warnings, [], 'Current local status snapshot unavailable');
    }
}
