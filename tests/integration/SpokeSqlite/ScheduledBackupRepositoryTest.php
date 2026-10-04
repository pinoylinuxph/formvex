<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\Contract\ScheduledBackupRepository;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoScheduledBackupRepository;
use Formvex\Spoke\Migrations\Version000019CreateScheduledBackupLifecycle;
use PDO;
use PHPUnit\Framework\TestCase;

final class ScheduledBackupRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-scheduled-backup-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/pre-upgrade', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
        $connection = $this->connection();
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT \'{}\')');
        new Version000019CreateScheduledBackupLifecycle()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultsAreDisabledAndDuePeriodClaimIsIdempotent(): void
    {
        $repository = $this->repository();
        $now = $this->now();
        $settings = $repository->settings($this->paths);

        self::assertFalse($settings->enabled);
        self::assertSame('monthly', $settings->frequency->value);
        self::assertSame(4, $settings->retentionCount);
        $enabled = new \Formvex\Spoke\Domain\Backup\ScheduledBackupSettings(true, $settings->frequency, 0, 0, 0, 4, $settings->lastDuePeriod, $settings->lastAttemptAt, $settings->lastSuccessAt, $settings->lastStatus, $settings->lastErrorCode, $settings->lastCandidateId, $settings->lastCleanupAt, $settings->updatedAt);
        $repository->saveSettings($this->paths, $enabled, $now);

        self::assertTrue($repository->claimDue($this->paths, '2026-10', $now));
        self::assertFalse($repository->claimDue($this->paths, '2026-10', $now));
    }

    public function testScheduledInventoryProtectsActiveDownloadAndRecordsCompletion(): void
    {
        $repository = $this->repository();
        $now = $this->now();
        $id = '01a0f744-d824-7576-ac66-c5f4924299aa';
        $repository->create($this->paths, $id, '2026-10', 'scheduled/' . $id . '.zip', '000019', $now);
        $repository->complete($this->paths, $id, 512, hash('sha256', 'scheduled'), $now);
        $archive = $repository->beginDownload($this->paths, $id, $now);

        self::assertSame('verified', $archive->state->value);
        self::assertSame(1, $archive->activeDownloads);
        self::assertSame($id, $repository->list($this->paths)[0]->publicId);

        $repository->finishDownload($this->paths, $id, $now);
        self::assertSame(0, $repository->find($this->paths, $id)?->activeDownloads);
        self::assertSame('spoke.scheduled_backup.candidate_verified', $this->connection()->query("SELECT event_name FROM audit_events WHERE event_name = 'spoke.scheduled_backup.candidate_verified'")->fetchColumn());
    }

    private function repository(): ScheduledBackupRepository
    {
        return new PdoScheduledBackupRepository();
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC'));
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
