<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use PDO;
use PHPUnit\Framework\TestCase;

final class InstallationStoreTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-sqlite-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/database', 0o700, true);
    }

    protected function tearDown(): void
    {
        unlink($this->temporaryRoot . '/database/formvex.sqlite');
        rmdir($this->temporaryRoot . '/database');
        rmdir($this->temporaryRoot);
    }

    public function testFreshAndRepeatedInitializationAreSafe(): void
    {
        $paths = new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot . '/database',
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot . '/logs',
            $this->temporaryRoot . '/exports',
            $this->temporaryRoot . '/diagnostics',
            $this->temporaryRoot . '/backups/scheduled',
            $this->temporaryRoot . '/backups/manual',
            $this->temporaryRoot . '/backups/temporary',
            $this->temporaryRoot . '/runtime',
        );
        $store = $this->createStore();

        $first = $store->initialize($paths);
        $second = $store->initialize($paths);
        $connection = new PDO('sqlite:' . $paths->databaseFile());

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame('0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $first->identity->installationId);
        self::assertSame($first->identity->installationId, $second->identity->installationId);
        $versions = $connection->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['000001', '000002', '000003', '000004', '000005'], $versions);
        self::assertSame(1, $connection->query('SELECT COUNT(*) FROM installation_metadata')->fetchColumn());
    }

    public function testUnknownMigrationStateFailsClosed(): void
    {
        $store = $this->createStore();
        $paths = new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot . '/database',
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot . '/logs',
            $this->temporaryRoot . '/exports',
            $this->temporaryRoot . '/diagnostics',
            $this->temporaryRoot . '/backups/scheduled',
            $this->temporaryRoot . '/backups/manual',
            $this->temporaryRoot . '/backups/temporary',
            $this->temporaryRoot . '/runtime',
        );

        $store->initialize($paths);
        $connection = new PDO('sqlite:' . $paths->databaseFile());
        $connection->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('999999', '2026-09-22T12:34:56.123456Z')");

        $this->expectException(InstallationFailure::class);
        $this->expectExceptionMessage('migration_state_invalid');
        $store->initialize($paths);
    }

    public function testExistingUnit03DatabaseIsUpgradedToTheCurrentSchema(): void
    {
        $paths = new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot . '/database',
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot . '/logs',
            $this->temporaryRoot . '/exports',
            $this->temporaryRoot . '/diagnostics',
            $this->temporaryRoot . '/backups/scheduled',
            $this->temporaryRoot . '/backups/manual',
            $this->temporaryRoot . '/backups/temporary',
            $this->temporaryRoot . '/runtime',
        );
        $connection = new PDO('sqlite:' . $paths->databaseFile());
        $connection->beginTransaction();
        $connection->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY NOT NULL, applied_at TEXT NOT NULL)');
        new Version000001CreateInstallationMetadata()->up($connection);
        new Version000002CreateLocalAdministratorAuth()->up($connection);
        $connection->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('000001', '2026-09-22T12:34:56.123456Z'), ('000002', '2026-09-22T12:34:56.123456Z')");
        $connection->exec("INSERT INTO installation_metadata (singleton_id, installation_id, initialized_at, schema_version) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', '2026-09-22T12:34:56.123456Z', '000002')");
        $connection->commit();

        $initialization = $this->createStore()->initialize($paths);

        self::assertFalse($initialization->created);
        self::assertSame('000005', $initialization->identity->schemaVersion);
        self::assertSame('000005', $connection->query('SELECT schema_version FROM installation_metadata WHERE singleton_id = 1')->fetchColumn());
        self::assertSame(1, $connection->query('SELECT COUNT(*) FROM installation_settings')->fetchColumn());
    }

    private function createStore(): PdoInstallationStore
    {
        $clock = new FixedClock();

        return new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $clock,
            ),
            $clock,
            new FixedIdentifierGenerator(),
        );
    }
}
