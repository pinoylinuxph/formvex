<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeFilesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Application\Backup\ScheduledBackupService;
use Formvex\Spoke\Domain\Backup\ScheduledBackupSettings;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Backup\ZipBackupArchiveStore;
use Formvex\Spoke\Infrastructure\Filesystem\LocalBackupOperationLock;
use Formvex\Spoke\Infrastructure\Filesystem\LocalRecoveryHoldStore;
use Formvex\Spoke\Infrastructure\Filesystem\LocalSpokeStorageResolver;
use Formvex\Spoke\Infrastructure\Persistence\PdoScheduledBackupRepository;
use Formvex\Spoke\Migrations\Version000019CreateScheduledBackupLifecycle;
use PDO;
use PHPUnit\Framework\TestCase;

final class ScheduledBackupServiceTest extends TestCase
{
    private string $root;

    private string $publicRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-scheduled-service-' . bin2hex(random_bytes(8));
        $this->publicRoot = $this->root . '/public';
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/pre-upgrade', 'backups/temporary', 'runtime', 'public/branding'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
        file_put_contents($this->paths->markerFile(), '{"installation_id":"test","schema_version":"000019"}');
        chmod($this->paths->markerFile(), 0o600);
        $connection = $this->connection();
        $connection->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $connection->exec("INSERT INTO schema_migrations VALUES ('000019', '2026-10-01T00:00:00.000000Z')");
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT \'{}\')');
        new Version000019CreateScheduledBackupLifecycle()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDueRunPublishesOneVerifiedArchiveAndRepeatedRunIsIdempotent(): void
    {
        $repository = new PdoScheduledBackupRepository();
        $current = $repository->settings($this->paths);
        $clock = $this->clock();
        $identifier = $this->identifier();
        $repository->saveSettings($this->paths, new ScheduledBackupSettings(true, $current->frequency, 0, 0, 0, 4, null, null, null, null, null, null, null, $current->updatedAt), $clock->now());
        $service = new ScheduledBackupService(
            new LocalSpokeStorageResolver(),
            $repository,
            new ZipBackupArchiveStore($this->publicRoot),
            new LocalBackupOperationLock(),
            new LocalRecoveryHoldStore(),
            $identifier,
            $clock,
        );

        $first = $service->run($this->root);
        $second = $service->run($this->root);

        self::assertSame('success', $first->status);
        self::assertSame('not_due', $second->status);
        self::assertCount(1, $service->list($this->root));
        self::assertSame('verified', $service->list($this->root)[0]->state->value);
        self::assertFileExists($this->root . '/backups/scheduled/' . $identifier->uuidV7($clock->now()) . '.zip');
    }

    private function clock(): Clock
    {
        return new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-01T00:01:00.000000Z', new DateTimeZone('UTC'));
            }
        };
    }

    private function identifier(): \Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator
    {
        return new class () implements \Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator {
            public function uuidV7(DateTimeImmutable $time): string
            {
                return '01a0f744-d824-7576-ac66-c5f4924299bb';
            }
        };
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->root . '/database/formvex.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
