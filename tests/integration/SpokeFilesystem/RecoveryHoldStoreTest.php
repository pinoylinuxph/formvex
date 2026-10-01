<?php

declare(strict_types=1);

namespace FormvexTests\Integration\SpokeFilesystem;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Filesystem\LocalRecoveryHoldStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecoveryHoldStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-recovery-hold-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/runtime', 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/runtime/recovery-hold.json')) {
            unlink($this->root . '/runtime/recovery-hold.json');
        }
        rmdir($this->root . '/runtime');
        rmdir($this->root);
    }

    public function testHoldIsPrivateAtomicAndClearsExplicitly(): void
    {
        $paths = $this->paths();
        $store = new LocalRecoveryHoldStore();
        $startedAt = new DateTimeImmutable('2026-10-01T00:00:00.000000Z');

        self::assertNull($store->current($paths));
        $store->activate($paths, $startedAt, 'restore', 'Testing restore hold.');
        self::assertSame(0o600, fileperms($paths->recoveryHoldFile()) & 0o777);
        self::assertSame('restore', $store->current($paths)?->operation);
        self::assertSame('Testing restore hold.', $store->current($paths)?->reason);

        $store->clear($paths);
        self::assertNull($store->current($paths));
    }

    public function testMalformedHoldFailsClosed(): void
    {
        $paths = $this->paths();
        file_put_contents($paths->recoveryHoldFile(), '{"operation":"restore"}');
        chmod($paths->recoveryHoldFile(), 0o600);

        $this->expectException(RuntimeException::class);
        new LocalRecoveryHoldStore()->current($paths);
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths(
            $this->root,
            $this->root . '/database',
            $this->root . '/secrets',
            $this->root . '/logs',
            $this->root . '/exports',
            $this->root . '/diagnostics',
            $this->root . '/backups/scheduled',
            $this->root . '/backups/manual',
            $this->root . '/backups/temporary',
            $this->root . '/runtime',
            $this->root . '/backups/pre-upgrade',
        );
    }
}
