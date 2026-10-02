<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Filesystem\LocalUpgradeInFlightTracker;
use PHPUnit\Framework\TestCase;

final class UpgradeInFlightTrackerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-inflight-' . bin2hex(random_bytes(8));
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

    public function testAdmittedRequestsAndWorkersAreCountedUntilFinished(): void
    {
        $tracker = new LocalUpgradeInFlightTracker();
        $paths = $this->paths();

        $request = $tracker->begin($paths, 'request');
        $worker = $tracker->begin($paths, 'delivery');

        self::assertIsString($request);
        self::assertIsString($worker);
        self::assertSame(2, $tracker->activeCount($paths));

        $tracker->finish($paths, $request);
        self::assertSame(1, $tracker->activeCount($paths));
        $tracker->finish($paths, $worker);
        self::assertSame(0, $tracker->activeCount($paths));
    }

    public function testDrainingRejectsNewWorkAndResetRemovesActiveLeases(): void
    {
        $tracker = new LocalUpgradeInFlightTracker();
        $paths = $this->paths();
        $lease = $tracker->begin($paths, 'request');
        self::assertIsString($lease);

        $tracker->startDraining($paths);
        self::assertNull($tracker->begin($paths, 'retention'));
        self::assertSame(1, $tracker->activeCount($paths));

        $tracker->stopDraining($paths);
        self::assertSame(0, $tracker->activeCount($paths));
        self::assertIsString($tracker->begin($paths, 'request'));
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
    }
}
