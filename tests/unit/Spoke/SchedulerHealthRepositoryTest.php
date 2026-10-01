<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoSchedulerHealthRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SchedulerHealthRepositoryTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-scheduler-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/database', 0o700, true);
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
        $connection->exec('CREATE TABLE delivery_worker_heartbeat (singleton_id INTEGER PRIMARY KEY, last_success_at TEXT NULL, last_result TEXT NULL, updated_at TEXT NOT NULL)');
        $connection->exec("INSERT INTO delivery_worker_heartbeat (singleton_id, updated_at) VALUES (1, '')");
        $connection->exec('CREATE TABLE installation_settings (singleton_id INTEGER PRIMARY KEY, retention_last_run_at TEXT NULL, retention_last_success_at TEXT NULL, retention_last_status TEXT NULL)');
        $connection->exec('INSERT INTO installation_settings (singleton_id) VALUES (1)');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testUnconfirmedJobsAreVisibleWithoutPrivateDetails(): void
    {
        $health = new PdoSchedulerHealthRepository()->status($this->paths, $this->now());

        self::assertSame('not_confirmed', $health->status);
        self::assertSame('not_confirmed', $health->job('delivery')->status);
        self::assertSame('not_confirmed', $health->job('retention')->status);
        self::assertStringNotContainsString($this->temporaryRoot, $health->message);
    }

    public function testRecentSuccessfulJobsAreHealthy(): void
    {
        $connection = $this->connection();
        $connection->exec("UPDATE delivery_worker_heartbeat SET last_success_at = '2026-10-01T00:59:30.000000Z', last_result = 'success', updated_at = '2026-10-01T00:59:30.000000Z' WHERE singleton_id = 1");
        $connection->exec("UPDATE installation_settings SET retention_last_run_at = '2026-10-01T00:00:00.000000Z', retention_last_success_at = '2026-10-01T00:00:00.000000Z', retention_last_status = 'completed' WHERE singleton_id = 1");

        $health = new PdoSchedulerHealthRepository()->status($this->paths, $this->now());

        self::assertSame('healthy', $health->status);
        self::assertSame('healthy', $health->job('delivery')->status);
        self::assertSame('healthy', $health->job('retention')->status);
    }

    public function testStaleAndFailedJobsAreDistinguished(): void
    {
        $connection = $this->connection();
        $connection->exec("UPDATE delivery_worker_heartbeat SET last_success_at = '2026-10-01T00:50:00.000000Z', last_result = 'success', updated_at = '2026-10-01T00:50:00.000000Z' WHERE singleton_id = 1");
        $connection->exec("UPDATE installation_settings SET retention_last_run_at = '2026-10-01T00:59:00.000000Z', retention_last_success_at = '2026-10-01T00:00:00.000000Z', retention_last_status = 'partial_failure' WHERE singleton_id = 1");

        $health = new PdoSchedulerHealthRepository()->status($this->paths, $this->now());

        self::assertSame('failed', $health->status);
        self::assertSame('stale', $health->job('delivery')->status);
        self::assertSame('failed', $health->job('retention')->status);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T01:00:00.000000Z', new DateTimeZone('UTC'));
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->temporaryRoot . '/database/formvex.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
