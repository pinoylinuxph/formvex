<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\Contract\BackupRepository;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoBackupRepository;
use Formvex\Spoke\Migrations\Version000015CreateStorageAllowanceAndExports;
use Formvex\Spoke\Migrations\Version000016CreateBackupInventory;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupRepositoryTest extends TestCase
{
    private string $root;
    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-backup-repository-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/pre-upgrade', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
        $connection = new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL)');
        new Version000015CreateStorageAllowanceAndExports()->up($connection);
        new Version000016CreateBackupInventory()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInventoryTransitionsAndActiveDownloadProtectionArePersisted(): void
    {
        $repository = $this->repository();
        $now = new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC'));
        $id = '01a0f744-d824-7576-ac66-c5f4924299fd';
        $repository->create($this->paths, $id, BackupKind::PRE_UPGRADE, 'pre_upgrade/' . $id . '.zip', '000016', $now);
        $repository->complete($this->paths, $id, 256, hash('sha256', 'backup'), $now);

        $archive = $repository->find($this->paths, $id);
        self::assertNotNull($archive);
        self::assertSame('pre_upgrade', $archive->kind->value);
        self::assertSame('verified', $archive->state->value);

        $download = $repository->beginDownload($this->paths, $id, $now);
        self::assertSame(1, $download->activeDownloads);
        $this->expectException(RuntimeException::class);
        $repository->delete($this->paths, $id, $now);
    }

    public function testDownloadCompletionAllowsExplicitDeletionOnly(): void
    {
        $repository = $this->repository();
        $now = new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC'));
        $id = '01a0f744-d824-7576-ac66-c5f492429fe0';
        $repository->create($this->paths, $id, BackupKind::MANUAL, 'manual/' . $id . '.zip', '000016', $now);
        $repository->complete($this->paths, $id, 256, hash('sha256', 'backup'), $now);
        $repository->beginDownload($this->paths, $id, $now);
        $repository->finishDownload($this->paths, $id);
        $repository->delete($this->paths, $id, $now);

        self::assertNull($repository->find($this->paths, $id));
        self::assertSame('spoke.backup.deleted', $this->connection()->query("SELECT event_name FROM audit_events WHERE event_name = 'spoke.backup.deleted'")->fetchColumn());
    }

    private function repository(): BackupRepository
    {
        return new PdoBackupRepository();
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
