<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use Formvex\Spoke\Migrations\Version000006CreateSubmissions;
use Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse;
use Formvex\Spoke\Migrations\Version000008CreateEmailDeliveryWorker;
use Formvex\Spoke\Migrations\Version000009CreateFormActivation;
use Formvex\Spoke\Migrations\Version000010CreateInstallationBranding;
use Formvex\Spoke\Migrations\Version000011CreateSubmissionReview;
use Formvex\Spoke\Migrations\Version000012CreateDeliveryReview;
use Formvex\Spoke\Migrations\Version000013CreateRetentionAndCleanup;
use Formvex\Spoke\Migrations\Version000014AddRetentionSuccessHeartbeat;
use Formvex\Spoke\Migrations\Version000015CreateStorageAllowanceAndExports;
use Formvex\Spoke\Migrations\Version000016CreateBackupInventory;
use PDO;
use Throwable;

final readonly class SqliteMigrationRunner
{
    public function __construct(
        private Version000001CreateInstallationMetadata $initialMigration,
        private Version000002CreateLocalAdministratorAuth $administratorMigration,
        private Version000003CreateInstallationSettings $settingsMigration,
        private Version000004CreateFormConfiguration $formConfigurationMigration,
        private Version000005CreateFormDiscovery $formDiscoveryMigration,
        private Clock $clock,
        private ?Version000006CreateSubmissions $submissionsMigration = null,
        private ?Version000007CreateSubmissionAbuse $submissionAbuseMigration = null,
        private ?Version000008CreateEmailDeliveryWorker $deliveryWorkerMigration = null,
        private ?Version000009CreateFormActivation $formActivationMigration = null,
        private ?Version000010CreateInstallationBranding $brandingMigration = null,
        private ?Version000011CreateSubmissionReview $submissionReviewMigration = null,
        private ?Version000012CreateDeliveryReview $deliveryReviewMigration = null,
        private ?Version000013CreateRetentionAndCleanup $retentionMigration = null,
        private ?Version000014AddRetentionSuccessHeartbeat $retentionSuccessHeartbeatMigration = null,
        private ?Version000015CreateStorageAllowanceAndExports $storageAllowanceMigration = null,
        private ?Version000016CreateBackupInventory $backupInventoryMigration = null,
    ) {
    }

    public function migrate(PDO $connection): string
    {
        try {
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS schema_migrations ('
                . 'version TEXT PRIMARY KEY NOT NULL, '
                . 'applied_at TEXT NOT NULL'
                . ')',
            );

            $migrations = [$this->initialMigration, $this->administratorMigration, $this->settingsMigration, $this->formConfigurationMigration, $this->formDiscoveryMigration, $this->submissionsMigration ?? new Version000006CreateSubmissions()];

            if ($this->submissionAbuseMigration !== null) {
                $migrations[] = $this->submissionAbuseMigration;
            }
            if ($this->deliveryWorkerMigration !== null) {
                $migrations[] = $this->deliveryWorkerMigration;
            }
            if ($this->formActivationMigration !== null) {
                $migrations[] = $this->formActivationMigration;
            }
            if ($this->brandingMigration !== null) {
                $migrations[] = $this->brandingMigration;
            }
            if ($this->submissionReviewMigration !== null) {
                $migrations[] = $this->submissionReviewMigration;
            }
            if ($this->deliveryReviewMigration !== null) {
                $migrations[] = $this->deliveryReviewMigration;
            }
            if ($this->retentionMigration !== null) {
                $migrations[] = $this->retentionMigration;
            }
            if ($this->retentionSuccessHeartbeatMigration !== null) {
                $migrations[] = $this->retentionSuccessHeartbeatMigration;
            }
            if ($this->storageAllowanceMigration !== null) {
                $migrations[] = $this->storageAllowanceMigration;
            }
            if ($this->backupInventoryMigration !== null) {
                $migrations[] = $this->backupInventoryMigration;
            }
            $knownVersions = array_map(
                static fn (Migration $migration): string => $migration->version(),
                $migrations,
            );
            $storedStatement = $connection->query('SELECT version FROM schema_migrations');

            if ($storedStatement === false) {
                throw new InstallationFailure('migration_state_invalid');
            }

            $storedVersions = $storedStatement->fetchAll(PDO::FETCH_COLUMN);

            foreach ($storedVersions as $storedVersion) {
                if (!is_string($storedVersion) || !in_array($storedVersion, $knownVersions, true)) {
                    throw new InstallationFailure('migration_state_invalid');
                }
            }

            $latestVersion = '';

            foreach ($migrations as $migration) {
                $version = $migration->version();
                $latestVersion = $version;
                $statement = $connection->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
                $statement->execute(['version' => $version]);

                if ($statement->fetchColumn() !== false) {
                    continue;
                }

                $migration->up($connection);
                $insert = $connection->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)',
                );
                $insert->execute([
                    'version' => $version,
                    'applied_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                ]);
            }

            return $latestVersion;
        } catch (InstallationFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new InstallationFailure('migration_failed');
        }
    }
}
