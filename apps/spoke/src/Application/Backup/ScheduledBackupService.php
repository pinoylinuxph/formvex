<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Backup;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\BackupArtifact;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Backup\Contract\BackupOperationLock;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Backup\Contract\ScheduledBackupRepository;
use Formvex\Spoke\Domain\Backup\ScheduledBackupArchive;
use Formvex\Spoke\Domain\Backup\ScheduledBackupFrequency;
use Formvex\Spoke\Domain\Backup\ScheduledBackupRunResult;
use Formvex\Spoke\Domain\Backup\ScheduledBackupSettings;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Throwable;

final readonly class ScheduledBackupService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private ScheduledBackupRepository $repository,
        private BackupArchiveStore $archiveStore,
        private BackupOperationLock $operationLock,
        private RecoveryHoldStore $recoveryHoldStore,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function settings(string $applicationRoot): ScheduledBackupSettings
    {
        return $this->repository->settings($this->paths($applicationRoot));
    }

    public function saveSettings(string $applicationRoot, ScheduledBackupSettings $settings): void
    {
        $this->validateSettings($settings);
        $paths = $this->paths($applicationRoot);
        $this->repository->saveSettings($paths, $settings, $this->clock->now());
    }

    /** @return list<ScheduledBackupArchive> */
    public function list(string $applicationRoot): array
    {
        return $this->repository->list($this->paths($applicationRoot));
    }

    public function run(string $applicationRoot): ScheduledBackupRunResult
    {
        $paths = $this->paths($applicationRoot);
        $this->storageResolver->assertOperatorOwns($paths);
        $settings = $this->repository->settings($paths);
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        try {
            $lock = $this->operationLock->acquire($paths, 'scheduled_backup');
        } catch (BackupFailure $failure) {
            return new ScheduledBackupRunResult('already_running', 0, 0, 0, 'Another scheduled backup is already running. No duplicate candidate was created.');
        } catch (Throwable) {
            return new ScheduledBackupRunResult('already_running', 0, 0, 0, 'Another scheduled backup may already be running. No duplicate candidate was created.');
        }

        $publicId = $this->identifierGenerator->uuidV7($now);
        $created = false;
        $artifact = null;
        try {
            if ($this->recoveryHoldStore->current($paths) !== null) {
                $this->repository->markRun($paths, 'recovery_hold', 'recovery_hold_active', null, $now);

                return new ScheduledBackupRunResult('recovery_hold', 0, 0, 0, 'Scheduled backup was deferred because the installation is in recovery hold. Existing archives were preserved.');
            }
            $this->cleanupAbandoned($paths, $now);
            $recoveredPins = $this->repository->recoverStaleDownloads($paths, $now->modify('-1 hour'), $now);
            if (!$settings->enabled) {
                return new ScheduledBackupRunResult('disabled', 0, 0, $recoveredPins, 'Scheduled backups are disabled. No archive was created.');
            }
            $duePeriod = $this->duePeriod($settings, $now);
            if ($duePeriod === null) {
                return new ScheduledBackupRunResult('not_due', 0, 0, $recoveredPins, 'The scheduled backup is not due yet.');
            }
            if (!$this->repository->claimDue($paths, $duePeriod, $now)) {
                return new ScheduledBackupRunResult('already_completed', 0, 0, 0, 'This scheduled period has already been claimed. No duplicate archive was created.');
            }
            $storageKey = 'scheduled/' . $publicId . '.zip';
            $schemaVersion = $this->archiveStore->currentSchemaVersion($paths);
            $this->repository->create($paths, $publicId, $duePeriod, $storageKey, $schemaVersion, $now);
            $created = true;
            $artifact = $this->archiveStore->create($paths, $publicId, BackupKind::SCHEDULED, $now);
            $this->repository->complete($paths, $publicId, $artifact->sizeBytes, $artifact->sha256, $this->clock->now());
            $rotated = $this->rotate($paths, $settings->retentionCount, $now);
            $this->repository->markRun($paths, 'success', null, $publicId, $this->clock->now());

            $deferred = $rotated['deferred'] + $recoveredPins;

            return new ScheduledBackupRunResult('success', 1, $rotated['deleted'], $deferred, $rotated['deferred'] > 0 ? 'The scheduled backup was verified. Some older copies remain temporarily because they are pinned for download.' : ($recoveredPins > 0 ? 'The scheduled backup was verified. A stale download pin was recovered and audited.' : 'The scheduled backup was verified and rotation completed safely.'));
        } catch (BackupFailure $failure) {
            $this->removeArtifact($paths, $artifact);
            if ($created) {
                $this->repository->fail($paths, $publicId, $failure->failureCode, $this->clock->now());
            }
            $this->repository->markRun($paths, 'failed', $failure->failureCode, $created ? $publicId : null, $this->clock->now());

            return new ScheduledBackupRunResult('failed', 0, 0, 0, $failure->getMessage());
        } catch (Throwable $failure) {
            $this->removeArtifact($paths, $artifact);
            if ($created) {
                $this->repository->fail($paths, $publicId, 'scheduled_backup_failed', $this->clock->now());
            }
            $this->repository->markRun($paths, 'failed', 'scheduled_backup_failed', $created ? $publicId : null, $this->clock->now());

            return new ScheduledBackupRunResult('failed', 0, 0, 0, 'The scheduled backup could not be completed safely. Existing verified archives were preserved.');
        } finally {
            $lock->release();
        }
    }

    /** @return array{archive: ScheduledBackupArchive, path: string} */
    public function beginDownload(string $applicationRoot, string $publicId): array
    {
        if (!preg_match('/\A[0-9a-f-]{16,80}\z/i', $publicId)) {
            throw new BackupFailure('backup_not_available', 'The selected scheduled backup is not available. Refresh Maintenance and try again.');
        }
        $paths = $this->paths($applicationRoot);
        $archive = $this->repository->find($paths, $publicId);
        if ($archive === null || !$archive->isDownloadable()) {
            throw new BackupFailure('backup_not_available', 'The selected scheduled backup is not available. Refresh Maintenance and try again.');
        }
        $path = $this->archiveStore->archivePath($paths, $archive->storageKey);
        if (!is_file($path) || is_link($path)) {
            throw new BackupFailure('backup_not_available', 'The selected scheduled backup is not available. Refresh Maintenance and try again.');
        }

        return ['archive' => $this->repository->beginDownload($paths, $publicId, $this->clock->now()), 'path' => $path];
    }

    public function finishDownload(string $applicationRoot, string $publicId): void
    {
        $this->repository->finishDownload($this->paths($applicationRoot), $publicId, $this->clock->now());
    }

    /** @return array{deleted: int, deferred: int} */
    private function rotate(PrivateStoragePaths $paths, int $retentionCount, DateTimeImmutable $now): array
    {
        $deleted = 0;
        $deferred = 0;
        $candidates = $this->repository->rotationCandidates($paths);
        while (count($candidates) - $deleted > $retentionCount) {
            $candidate = $candidates[$deleted] ?? null;
            if (!$candidate instanceof ScheduledBackupArchive) {
                break;
            }
            if ($candidate->activeDownloads > 0) {
                $deferred++;
                $deleted++;
                continue;
            }
            $this->archiveStore->delete($paths, $candidate->storageKey);
            $this->repository->delete($paths, $candidate->publicId, $now);
            $deleted++;
        }

        return ['deleted' => $deleted - $deferred, 'deferred' => $deferred];
    }

    private function duePeriod(ScheduledBackupSettings $settings, DateTimeImmutable $now): ?string
    {
        $time = ((int) $now->format('H') * 60) + (int) $now->format('i');
        $configuredTime = ($settings->hour * 60) + $settings->minute;
        $period = match ($settings->frequency) {
            ScheduledBackupFrequency::DAILY => $now->format('Y-m-d'),
            ScheduledBackupFrequency::WEEKLY => $now->format('o-\WW'),
            ScheduledBackupFrequency::MONTHLY => $now->format('Y-m'),
        };
        if ($settings->frequency === ScheduledBackupFrequency::WEEKLY) {
            $isoWeekday = $settings->weekday === 0 ? 7 : $settings->weekday;
            $weekStart = $now->modify('monday this week')->setTime(0, 0);
            $scheduledAt = $weekStart->modify('+' . ($isoWeekday - 1) . ' days')->setTime($settings->hour, $settings->minute);
            if ($now < $scheduledAt) {
                return null;
            }
        } elseif ($time < $configuredTime) {
            return null;
        }

        return $period === $settings->lastDuePeriod ? null : $period;
    }

    private function removeArtifact(PrivateStoragePaths $paths, ?BackupArtifact $artifact): void
    {
        if (!$artifact instanceof BackupArtifact) {
            return;
        }
        try {
            $this->archiveStore->delete($paths, $artifact->storageKey);
        } catch (Throwable) {
            // The failed inventory row remains authoritative for safe cleanup.
        }
    }

    private function cleanupAbandoned(PrivateStoragePaths $paths, DateTimeImmutable $now): void
    {
        foreach ($this->repository->abandonedCandidates($paths, $now->modify('-24 hours')) as $candidate) {
            try {
                $this->archiveStore->delete($paths, $candidate->storageKey);
                $this->repository->fail($paths, $candidate->publicId, 'abandoned_candidate', $now);
            } catch (Throwable) {
                // An uncertain candidate remains visible for conservative administrator review.
            }
        }
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function validateSettings(ScheduledBackupSettings $settings): void
    {
        if ($settings->weekday < 0 || $settings->weekday > 6 || $settings->hour < 0 || $settings->hour > 23 || $settings->minute < 0 || $settings->minute > 59 || $settings->retentionCount < 1 || $settings->retentionCount > 12) {
            throw new BackupFailure('scheduled_backup_settings_invalid', 'Scheduled backup settings are outside the supported ranges.');
        }
    }
}
