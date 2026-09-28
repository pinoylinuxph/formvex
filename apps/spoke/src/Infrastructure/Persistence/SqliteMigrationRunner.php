<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use PDO;
use Throwable;

final readonly class SqliteMigrationRunner
{
    public function __construct(
        private Version000001CreateInstallationMetadata $initialMigration,
        private Version000002CreateLocalAdministratorAuth $administratorMigration,
        private Version000003CreateInstallationSettings $settingsMigration,
        private Clock $clock,
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

            $migrations = [$this->initialMigration, $this->administratorMigration, $this->settingsMigration];
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
