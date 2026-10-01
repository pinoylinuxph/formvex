<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Retention;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\Retention\Contract\RetentionLock;
use Formvex\Spoke\Domain\Retention\Contract\RetentionRepository;
use Formvex\Spoke\Domain\Retention\RetentionCleanupResult;
use Throwable;

final readonly class RunRetentionCleanupHandler
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private RetentionLock $retentionLock,
        private InstallationSettingsStore $settingsStore,
        private RetentionRepository $retentionRepository,
        private Clock $clock,
        private ?RecoveryHoldStore $recoveryHoldStore = null,
    ) {
    }

    public function handle(RunRetentionCleanup $command): RetentionCleanupResult
    {
        $paths = $this->storageResolver->resolve($command->applicationRoot);
        if ($this->recoveryHoldStore?->current($paths) !== null) {
            return new RetentionCleanupResult('recovery_hold', 0, 0, 0, 0, 'recovery_hold_active', false);
        }
        $settings = $this->settingsStore->get($paths);
        $batchLimit = max(1, min(1000, $command->batchLimit));

        try {
            $lock = $this->retentionLock->acquire($paths);
        } catch (InstallationFailure $failure) {
            if ($failure->failureCode === 'installation_in_progress') {
                return RetentionCleanupResult::locked();
            }

            throw $failure;
        }

        try {
            return $this->retentionRepository->cleanup($paths, $settings, $this->clock->now(), $batchLimit);
        } catch (Throwable $failure) {
            return new RetentionCleanupResult('partial_failure', 0, 0, 0, 1, 'cleanup_failed', false);
        } finally {
            $lock->release();
        }
    }
}
