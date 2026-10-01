<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\StorageCapacityGuard;
use Formvex\Spoke\Domain\Storage\Contract\StorageSettingsRepository;
use Formvex\Spoke\Domain\Storage\Contract\StorageUsageReader;
use Formvex\Spoke\Domain\Storage\StorageCapacityDecision;
use Throwable;

final readonly class LocalStorageCapacityGuard implements StorageCapacityGuard
{
    public function __construct(
        private StorageSettingsRepository $settingsRepository,
        private StorageUsageReader $usageReader,
        private ?BoundedOperationalLogWriter $logWriter = null,
    ) {
    }

    public function evaluate(PrivateStoragePaths $paths, int $additionalBytes = 0): StorageCapacityDecision
    {
        try {
            $settings = $this->settingsRepository->get($paths);
            $usage = $this->usageReader->read($paths, $settings);
        } catch (Throwable) {
            return $this->rejected($paths, 'measurement_unavailable');
        }

        if ($usage->state->value === 'unavailable') {
            return $this->rejected($paths, 'measurement_unavailable');
        }
        if ($usage->physicalFreeBytes !== null && $usage->physicalFreeBytes <= 0) {
            return $this->rejected($paths, 'physical_storage_full');
        }
        if ($usage->liveBytes + max(0, $additionalBytes) >= $settings->allowanceBytes) {
            return $this->rejected($paths, 'logical_allowance_reached');
        }

        return StorageCapacityDecision::allowed();
    }

    private function rejected(PrivateStoragePaths $paths, string $code): StorageCapacityDecision
    {
        try {
            $this->logWriter?->append($paths, 'storage-capacity', 'capacity.' . $code);
        } catch (Throwable) {
            // Capacity protection remains authoritative when the optional log is unavailable.
        }

        return StorageCapacityDecision::rejected($code);
    }
}
