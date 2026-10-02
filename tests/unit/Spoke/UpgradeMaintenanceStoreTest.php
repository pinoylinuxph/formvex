<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Filesystem\LocalUpgradeMaintenanceStore;
use PHPUnit\Framework\TestCase;

final class UpgradeMaintenanceStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-upgrade-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/runtime', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/runtime/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root . '/runtime');
        rmdir($this->root);
    }

    public function testStateIsPrivateAtomicAndCanTransitionThenClear(): void
    {
        $paths = $this->paths();
        $store = new LocalUpgradeMaintenanceStore();
        $started = new DateTimeImmutable('2026-10-02T04:00:00.000000Z');
        $deadline = new DateTimeImmutable('2026-10-02T04:05:00.000000Z');

        $state = $store->begin($paths, 'op-123', '1.0.0', '1.0.1', '000016', '000016', $started, $deadline);
        self::assertSame('draining', $state->state);
        self::assertSame(0o600, fileperms($paths->upgradeMaintenanceFile()) & 0o777);

        $published = $store->transition($paths, $state, 'published');
        self::assertSame('published', $published->state);
        self::assertSame('op-123', $store->current($paths)?->operationId);

        $store->clear($paths, 'op-123');
        self::assertNull($store->current($paths));
    }

    public function testSecondOperationIsRejectedWithoutReplacingTheFirstState(): void
    {
        $paths = $this->paths();
        $store = new LocalUpgradeMaintenanceStore();
        $now = new DateTimeImmutable('2026-10-02T04:00:00.000000Z');
        $store->begin($paths, 'first', '1.0.0', '1.0.1', '000016', '000016', $now, $now->modify('+5 minutes'));

        $this->expectException(InstallationFailure::class);
        $this->expectExceptionMessage('upgrade_in_progress');
        $store->begin($paths, 'second', '1.0.0', '1.0.2', '000016', '000016', $now, $now->modify('+5 minutes'));
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
    }
}
