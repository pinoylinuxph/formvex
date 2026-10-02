<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\DeliveryWorkerResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoWorkerHeartbeatStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerHeartbeatStoreTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-heartbeat-' . bin2hex(random_bytes(8));
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
        $connection->exec('CREATE TABLE delivery_worker_heartbeat (singleton_id INTEGER PRIMARY KEY, last_success_at TEXT NULL, last_result TEXT NULL, last_claimed INTEGER NOT NULL DEFAULT 0, last_sent INTEGER NOT NULL DEFAULT 0, last_failed INTEGER NOT NULL DEFAULT 0, last_uncertain INTEGER NOT NULL DEFAULT 0, last_deferred INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL)');
        $connection->exec("INSERT INTO delivery_worker_heartbeat (singleton_id, last_success_at, updated_at) VALUES (1, '2026-10-01T09:32:01.366822Z', '2026-10-01T09:32:01.366822Z')");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testEmptySuccessfulRunAdvancesTheSuccessHeartbeat(): void
    {
        $now = new DateTimeImmutable('2026-10-02T04:58:34.919752Z', new DateTimeZone('UTC'));

        new PdoWorkerHeartbeatStore()->recordSuccess($this->paths, $now, new DeliveryWorkerResult(0, 0, 0, 0, 0, 0));

        $connection = $this->connection();
        $row = $connection->query('SELECT last_success_at, last_result, updated_at FROM delivery_worker_heartbeat WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);

        self::assertSame([
            'last_success_at' => '2026-10-02T04:58:34.919752Z',
            'last_result' => 'success',
            'updated_at' => '2026-10-02T04:58:34.919752Z',
        ], array_intersect_key($row, array_flip(['last_success_at', 'last_result', 'updated_at'])));
    }

    public function testFailedRunPreservesTheLastSuccessfulHeartbeat(): void
    {
        $now = new DateTimeImmutable('2026-10-02T04:58:34.919752Z', new DateTimeZone('UTC'));

        new PdoWorkerHeartbeatStore()->recordFailure($this->paths, $now, new DeliveryWorkerResult(0, 0, 0, 0, 0, 0, false));

        $connection = $this->connection();
        $row = $connection->query('SELECT last_success_at, last_result, updated_at FROM delivery_worker_heartbeat WHERE singleton_id = 1')->fetch(PDO::FETCH_ASSOC);

        self::assertSame('2026-10-01T09:32:01.366822Z', $row['last_success_at']);
        self::assertSame('failure', $row['last_result']);
        self::assertSame('2026-10-02T04:58:34.919752Z', $row['updated_at']);
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
