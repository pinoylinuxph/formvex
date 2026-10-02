<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use Formvex\Core\Release\ReleasePackageVerifier;
use Formvex\Spoke\Application\Release\ReleaseUpgradeService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\BackupState;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\FinalBackupCreator;
use Formvex\Spoke\Domain\Release\Contract\PairedCheckpointRestorer;
use Formvex\Spoke\Domain\Release\Contract\ReleaseHealthChecker;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMigrationApplier;
use Formvex\Spoke\Domain\Release\Contract\ReleasePackagePublisher;
use Formvex\Spoke\Domain\Release\Contract\ReleaseSchedulerReadiness;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use Formvex\Spoke\Domain\Release\Contract\UpgradeOperationLock;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use Formvex\Spoke\Domain\Release\UpgradeMaintenanceState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ReleaseUpgradeServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-release-workflow-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/runtime', 0o700, true);
        mkdir($this->root . '/backups/pre-upgrade', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testFinalBackupPrecedesPublicationAndMigration(): void
    {
        $events = [];
        $store = new TestMaintenanceStore($events);
        $publisher = new TestPackagePublisher($events);
        $migration = new TestMigrationApplier($events);
        $service = $this->service($events, $store, $publisher, $migration);
        $package = $this->package('1.0.1');

        $result = $service->upgrade($this->root, $package, null, 0);

        self::assertSame('1.0.1', $result->releaseVersion);
        self::assertLessThan(array_search('publish', $events, true), array_search('backup', $events, true));
        self::assertLessThan(array_search('migrate', $events, true), array_search('publish', $events, true));
        self::assertNull($store->current($this->paths()));
        self::assertContains('stop-draining', $events);
    }

    public function testMigrationFailureLeavesHoldAndPairedRollbackReopensOnlyAfterChecks(): void
    {
        $events = [];
        $store = new TestMaintenanceStore($events);
        $publisher = new TestPackagePublisher($events);
        $migration = new TestMigrationApplier($events, true);
        $service = $this->service($events, $store, $publisher, $migration);
        $package = $this->package('1.0.1');
        $checkpoint = $this->root . '/backups/pre-upgrade/checkpoint.zip';
        file_put_contents($checkpoint, 'verified checkpoint placeholder');

        try {
            $service->upgrade($this->root, $package, null, 0);
            self::fail('The simulated migration failure should stop the upgrade.');
        } catch (ReleaseOperationFailure $failure) {
            self::assertSame('upgrade_failed', $failure->failureCode);
        }

        self::assertNotNull($store->current($this->paths()));
        self::assertStringStartsWith('failed_', $store->current($this->paths())?->state ?? '');
        self::assertNotContains('clear', $events);

        $migration->shouldFail = false;
        $result = $service->rollback($this->root, $checkpoint, $package, hash_file('sha256', $package), 0);

        self::assertSame('1.0.1', $result->releaseVersion);
        self::assertNull($store->current($this->paths()));
        self::assertContains('restore', $events);
        self::assertContains('stop-draining', $events);
    }

    public function testFinalBackupFailureStopsBeforePublicationAndMigration(): void
    {
        $events = [];
        $store = new TestMaintenanceStore($events);
        $publisher = new TestPackagePublisher($events);
        $migration = new TestMigrationApplier($events);
        $service = $this->service($events, $store, $publisher, $migration, true);

        try {
            $service->upgrade($this->root, $this->package('1.0.1'), null, 0);
            self::fail('The simulated final-backup failure should stop the upgrade.');
        } catch (ReleaseOperationFailure $failure) {
            self::assertSame('upgrade_failed', $failure->failureCode);
        }

        self::assertNotContains('stage', $events);
        self::assertNotContains('publish', $events);
        self::assertNotContains('migrate', $events);
        self::assertNotNull($store->current($this->paths()));
    }

    /** @param list<string> $events */
    private function service(array &$events, TestMaintenanceStore $store, TestPackagePublisher $publisher, TestMigrationApplier $migration, bool $backupShouldFail = false): ReleaseUpgradeService
    {
        $paths = $this->paths();
        return new ReleaseUpgradeService(
            new TestStorageResolver($paths),
            new ReleasePackageVerifier(),
            $store,
            new TestOperationLock($events),
            new TestInFlightTracker($events),
            new TestFinalBackupCreator($events, $backupShouldFail),
            new TestArchiveStore(),
            new TestCheckpointRestorer($events),
            $publisher,
            $migration,
            new TestHealthChecker($events),
            new TestSchedulerReadiness($events),
            new TestClock(),
            new TestIdentifierGenerator(),
            $this->root . '/code',
        );
    }

    private function package(string $version): string
    {
        $path = $this->root . '/package-' . $version . '.zip';
        $files = [
            'apps/spoke/public/index.php' => '<?php',
            'build/client.js' => 'client',
            'build/spoke-admin.js' => 'admin',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php',
            'LICENSE' => 'MIT',
            'NOTICE' => 'Copyright (c) 2026 Royjieviv',
        ];
        $manifestFiles = [];
        foreach ($files as $name => $contents) {
            $manifestFiles[] = ['path' => $name, 'sha256' => hash('sha256', $contents)];
        }
        $manifest = json_encode([
            'schema_version' => 1,
            'release_version' => $version,
            'schema_compatibility' => ['minimum' => '000016', 'maximum' => '000016'],
            'files' => $manifestFiles,
        ], JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->addFromString('RELEASE-MANIFEST.json', $manifest));
        self::assertTrue($zip->close());

        return $path;
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}

final class TestStorageResolver implements SpokeStorageResolver
{
    public function __construct(private readonly PrivateStoragePaths $paths)
    {
    }

    public function resolve(string $applicationRoot): PrivateStoragePaths
    {
        return $this->paths;
    }

    public function assertOperatorOwns(PrivateStoragePaths $paths): void
    {
    }
}

final class TestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-02T05:00:00.000000Z');
    }
}

final class TestIdentifierGenerator implements IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return '01a0f300-0000-7000-8000-000000000001';
    }
}

final class TestLock implements StorageLock
{
    public function release(): void
    {
    }
}

final class TestOperationLock implements UpgradeOperationLock
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        $this->events[] = 'lock';

        return new TestLock();
    }
}

final class TestInFlightTracker implements UpgradeInFlightTracker
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function begin(PrivateStoragePaths $paths, string $kind): ?string
    {
        return null;
    }

    public function finish(PrivateStoragePaths $paths, string $leaseId): void
    {
    }

    public function startDraining(PrivateStoragePaths $paths): void
    {
        $this->events[] = 'drain';
    }

    public function stopDraining(PrivateStoragePaths $paths): void
    {
        $this->events[] = 'stop-draining';
    }

    public function activeCount(PrivateStoragePaths $paths): int
    {
        return 0;
    }
}

final class TestFinalBackupCreator implements FinalBackupCreator
{
    /** @param list<string> $events */
    public function __construct(private array &$events, private readonly bool $shouldFail = false)
    {
    }

    public function create(string $applicationRoot, PrivateStoragePaths $paths): BackupArchive
    {
        $this->events[] = 'backup';
        if ($this->shouldFail) {
            throw new RuntimeException('simulated final backup failure');
        }

        return new BackupArchive('01a0f300-0000-7000-8000-000000000002', BackupKind::PRE_UPGRADE, BackupState::VERIFIED, 'pre_upgrade/checkpoint.zip', new DateTimeImmutable('2026-10-02T05:00:00Z'), new DateTimeImmutable('2026-10-02T05:00:00Z'), 10, str_repeat('a', 64), '000016', 1, null, 0, 0);
    }
}

final class TestArchiveStore implements BackupArchiveStore
{
    public function currentSchemaVersion(PrivateStoragePaths $paths): string
    {
        return '000016';
    }

    public function create(PrivateStoragePaths $paths, string $publicId, BackupKind $kind, DateTimeImmutable $createdAt): \Formvex\Spoke\Domain\Backup\BackupArtifact
    {
        throw new RuntimeException('not used');
    }

    public function verify(PrivateStoragePaths $paths, string $archivePath): string
    {
        return '000016';
    }

    public function archivePath(PrivateStoragePaths $paths, string $storageKey): string
    {
        return $paths->preUpgradeBackups . '/' . basename($storageKey);
    }

    public function delete(PrivateStoragePaths $paths, string $storageKey): void
    {
    }

    public function restore(PrivateStoragePaths $paths, string $archivePath): void
    {
    }
}

final class TestPackagePublisher implements ReleasePackagePublisher
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function stage(string $archivePath, string $operationId, string $privateRuntime): string
    {
        $this->events[] = 'stage';

        return $privateRuntime . '/staged-release';
    }

    public function publish(string $stagingPath, string $projectRoot): void
    {
        $this->events[] = 'publish';
    }

    public function discard(string $stagingPath): void
    {
        $this->events[] = 'discard';
    }
}

final class TestMigrationApplier implements ReleaseMigrationApplier
{
    /** @param list<string> $events */
    public function __construct(private array &$events, public bool $shouldFail = false)
    {
    }

    public function apply(PrivateStoragePaths $paths): string
    {
        $this->events[] = 'migrate';
        if ($this->shouldFail) {
            throw new RuntimeException('simulated migration failure');
        }

        return '000016';
    }
}

final class TestHealthChecker implements ReleaseHealthChecker
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function assertHealthy(string $applicationRoot): void
    {
        $this->events[] = 'health';
    }
}

final class TestSchedulerReadiness implements ReleaseSchedulerReadiness
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function assertReady(PrivateStoragePaths $paths, DateTimeImmutable $now): void
    {
        $this->events[] = 'scheduler';
    }
}

final class TestCheckpointRestorer implements PairedCheckpointRestorer
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function restore(string $applicationRoot, string $archivePath): void
    {
        $this->events[] = 'restore';
    }
}

final class TestMaintenanceStore implements UpgradeMaintenanceStore
{
    private ?UpgradeMaintenanceState $state = null;

    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function current(PrivateStoragePaths $paths): ?UpgradeMaintenanceState
    {
        return $this->state;
    }

    public function begin(PrivateStoragePaths $paths, string $operationId, string $currentRelease, string $targetRelease, string $currentSchema, string $targetSchema, DateTimeImmutable $startedAt, DateTimeImmutable $drainDeadlineAt): UpgradeMaintenanceState
    {
        $this->state = new UpgradeMaintenanceState($operationId, $currentRelease, $targetRelease, $currentSchema, $targetSchema, 'draining', $startedAt, $drainDeadlineAt);
        $this->events[] = 'begin';

        return $this->state;
    }

    public function transition(PrivateStoragePaths $paths, UpgradeMaintenanceState $state, string $nextState): UpgradeMaintenanceState
    {
        $this->state = $state->withState($nextState);
        $this->events[] = $nextState;

        return $this->state;
    }

    public function clear(PrivateStoragePaths $paths, string $operationId): void
    {
        $this->state = null;
        $this->events[] = 'clear';
    }
}
