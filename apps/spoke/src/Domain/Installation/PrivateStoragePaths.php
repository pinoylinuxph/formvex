<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class PrivateStoragePaths
{
    public function __construct(
        public string $applicationRoot,
        public string $database,
        public string $secrets,
        public string $logs,
        public string $exports,
        public string $diagnostics,
        public string $scheduledBackups,
        public string $manualBackups,
        public string $temporaryBackups,
        public string $runtime,
        public string $preUpgradeBackups = '',
    ) {
    }

    public function databaseFile(): string
    {
        return $this->database . DIRECTORY_SEPARATOR . 'formvex.sqlite';
    }

    public function markerFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'installation-state.json';
    }

    public function lockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'installation.lock';
    }

    public function retentionLockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'retention.lock';
    }

    public function backupLockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'backup.lock';
    }

    public function restoreLockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'restore.lock';
    }

    public function recoveryHoldFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'recovery-hold.json';
    }

    public function upgradeMaintenanceFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'upgrade-maintenance.json';
    }

    public function upgradeLockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'upgrade.lock';
    }

    public function upgradeInFlightFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'upgrade-inflight.json';
    }

    public function upgradeInFlightLockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'upgrade-inflight.lock';
    }
}
