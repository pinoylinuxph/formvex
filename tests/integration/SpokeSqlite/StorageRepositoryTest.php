<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\StorageExport;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use Formvex\Spoke\Infrastructure\Persistence\PdoStorageExportRepository;
use Formvex\Spoke\Infrastructure\Persistence\PdoStorageSettingsRepository;
use Formvex\Spoke\Migrations\Version000015CreateStorageAllowanceAndExports;
use PDO;
use PHPUnit\Framework\TestCase;

final class StorageRepositoryTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-storage-repository-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths(
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
        $connection = $this->connection();
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL)');
        new Version000015CreateStorageAllowanceAndExports()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testStorageSettingsPersistWithRequiredAuditEvent(): void
    {
        $repository = new PdoStorageSettingsRepository();
        self::assertSame(2000000000, $repository->get($this->paths)->allowanceBytes);

        $repository->save($this->paths, new StorageSettings(3000000000, 70, 95), $this->time());
        $saved = $repository->get($this->paths);

        self::assertSame(3000000000, $saved->allowanceBytes);
        self::assertSame(70, $saved->normalWarningPercent);
        self::assertSame(95, $saved->criticalWarningPercent);
        self::assertSame('spoke.settings.storage_saved', $this->connection()->query('SELECT event_name FROM audit_events')->fetchColumn());
        self::assertSame('{"previous_allowance_bytes":2000000000,"previous_normal_warning_percent":80,"previous_critical_warning_percent":90,"allowance_bytes":3000000000,"normal_warning_percent":70,"critical_warning_percent":95}', $this->connection()->query('SELECT metadata_json FROM audit_events')->fetchColumn());
    }

    public function testExportMetadataDownloadAndExpiryRemainPrivateAndTraceable(): void
    {
        $repository = new PdoStorageExportRepository();
        $created = $this->time();
        $publicId = '01a0f2ef-1098-733f-bf9d-26dc71a7c07e';
        $export = new StorageExport($publicId, 2, 128, $created, $created->modify('+15 minutes'), null, 'available');
        file_put_contents($this->paths->exports . '/' . $publicId . '.csv', 'private export');

        $repository->create($this->paths, $export, 'classification=normal');
        self::assertTrue($repository->hasActive($this->paths, $created));
        self::assertSame('available', $repository->find($this->paths, $publicId)?->status);

        $repository->markDownloaded($this->paths, $publicId, $created->modify('+1 minute'));
        self::assertSame('downloaded', $repository->find($this->paths, $publicId)?->status);
        self::assertSame(2, (int) $this->connection()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn());

        self::assertSame(1, $repository->expire($this->paths, $created->modify('+16 minutes')));
        self::assertFileDoesNotExist($this->paths->exports . '/' . $publicId . '.csv');
        self::assertSame('expired', $repository->find($this->paths, $publicId)?->status);
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC'));
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
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
