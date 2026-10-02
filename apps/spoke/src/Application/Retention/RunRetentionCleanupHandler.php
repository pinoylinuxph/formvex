<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Retention;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
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
        private ?UpgradeMaintenanceStore $upgradeMaintenanceStore = null,
        private ?UpgradeInFlightTracker $inFlightTracker = null,
    ) {
    }

    public function handle(RunRetentionCleanup $command): RetentionCleanupResult
    {
        $paths = $this->storageResolver->resolve($command->applicationRoot);
        $lease = $this->inFlightTracker?->begin($paths, 'retention');
        if ($this->inFlightTracker !== null && $lease === null) {
            return new RetentionCleanupResult('recovery_hold', 0, 0, 0, 0, 'recovery_hold_active', false);
        }
        if ($this->recoveryHoldStore?->current($paths) !== null || $this->upgradeMaintenanceStore?->current($paths) !== null) {
            $this->finishLease($paths, $lease);
            return new RetentionCleanupResult('recovery_hold', 0, 0, 0, 0, 'recovery_hold_active', false);
        }
        $settings = $this->settingsStore->get($paths);
        $batchLimit = max(1, min(1000, $command->batchLimit));

        try {
            $lock = $this->retentionLock->acquire($paths);
        } catch (InstallationFailure $failure) {
            $this->finishLease($paths, $lease);
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
            $this->finishLease($paths, $lease);
            $lock->release();
        }
    }

    private function finishLease(\Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths, ?string $lease): void
    {
        if ($lease !== null) {
            try {
                $this->inFlightTracker?->finish($paths, $lease);
            } catch (Throwable) {
                // The bounded drain timeout remains the safe recovery boundary for a stale lease.
            }
        }
    }
}
