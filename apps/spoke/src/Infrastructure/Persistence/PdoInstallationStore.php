<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\Contract\InstallationStore;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\InstallationIdentity;
use Formvex\Spoke\Domain\Installation\InstallationInitialization;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use Throwable;

final readonly class PdoInstallationStore implements InstallationStore
{
    public function __construct(
        private SqliteMigrationRunner $migrationRunner,
        private Clock $clock,
        private IdentifierGenerator $identifierGenerator,
    ) {
    }

    public function initialize(PrivateStoragePaths $paths): InstallationInitialization
    {
        $connection = null;

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->beginTransaction();

            $schemaVersion = $this->migrationRunner->migrate($connection);
            $statement = $connection->query(
                'SELECT installation_id, schema_version FROM installation_metadata ORDER BY singleton_id',
            );

            if ($statement === false) {
                throw new InstallationFailure('installation_state_invalid');
            }

            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 1) {
                throw new InstallationFailure('installation_state_invalid');
            }

            if ($rows !== []) {
                $row = $rows[0] ?? null;

                if (
                    !is_array($row)
                    || !isset($row['installation_id'], $row['schema_version'])
                    || !is_string($row['installation_id'])
                    || !is_string($row['schema_version'])
                ) {
                    throw new InstallationFailure('installation_state_invalid');
                }

                if ($row['schema_version'] !== $schemaVersion) {
                    if (!$this->isUpgradeVersion($row['schema_version'], $schemaVersion)) {
                        throw new InstallationFailure('installation_state_invalid');
                    }

                    $upgrade = $connection->prepare(
                        'UPDATE installation_metadata SET schema_version = :schema_version WHERE singleton_id = 1',
                    );
                    $upgrade->execute(['schema_version' => $schemaVersion]);

                    if ($upgrade->rowCount() !== 1) {
                        throw new InstallationFailure('installation_state_invalid');
                    }
                }

                $connection->commit();

                return new InstallationInitialization(
                    new InstallationIdentity($row['installation_id'], $schemaVersion),
                    false,
                );
            }

            $identity = new InstallationIdentity(
                $this->identifierGenerator->uuidV7($this->clock->now()),
                $schemaVersion,
            );
            $insert = $connection->prepare(
                'INSERT INTO installation_metadata '
                . '(singleton_id, installation_id, initialized_at, schema_version) '
                . 'VALUES (1, :installation_id, :initialized_at, :schema_version)',
            );
            $insert->execute([
                'installation_id' => $identity->installationId,
                'initialized_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'schema_version' => $identity->schemaVersion,
            ]);
            $connection->commit();

            return new InstallationInitialization($identity, true);
        } catch (InstallationFailure $failure) {
            if ($connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        } catch (Throwable) {
            if ($connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new InstallationFailure('database_initialization_failed');
        }
    }

    private function isUpgradeVersion(string $storedVersion, string $latestVersion): bool
    {
        return preg_match('/^\d{6}$/', $storedVersion) === 1
            && preg_match('/^\d{6}$/', $latestVersion) === 1
            && (int) $storedVersion < (int) $latestVersion;
    }
}
