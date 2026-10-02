<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Release;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use PDO;
use Throwable;

final readonly class HealthCheckService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private RecoveryHoldStore $recoveryHoldStore,
        private ?UpgradeMaintenanceStore $upgradeMaintenanceStore = null,
    ) {
    }

    public function check(string $applicationRoot, bool $allowMaintenance = false): HealthCheckResult
    {
        try {
            $paths = $this->storageResolver->resolve($applicationRoot);
            if ($this->recoveryHoldStore->current($paths) !== null || (!$allowMaintenance && $this->upgradeMaintenanceStore?->current($paths) !== null)) {
                return new HealthCheckResult(false, 'unavailable', 'The installation remains unavailable under a server-side recovery or release hold.');
            }
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $integrityStatement = $connection->query('PRAGMA integrity_check');
            $schemaStatement = $connection->query('SELECT schema_version FROM installation_metadata WHERE singleton_id = 1');
            $integrity = $integrityStatement === false ? null : $integrityStatement->fetchColumn();
            $schema = $schemaStatement === false ? null : $schemaStatement->fetchColumn();
            if ($integrity !== 'ok' || !is_string($schema) || !preg_match('/\A\d{6}\z/', $schema)) {
                return new HealthCheckResult(false, 'failed', 'The local schema or database integrity check did not pass. Keep the installation unavailable and repair it server-side.');
            }

            return new HealthCheckResult(true, 'healthy', 'The release, private storage, SQLite integrity, and installation schema checks passed.');
        } catch (Throwable) {
            return new HealthCheckResult(false, 'failed', 'The release health checks could not be completed safely. Keep the installation unavailable until the hosting administrator reviews the private installation.');
        }
    }
}
