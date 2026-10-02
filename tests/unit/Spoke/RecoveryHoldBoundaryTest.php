<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Backup\RecoveryHold;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use Formvex\Spoke\Domain\Release\UpgradeMaintenanceState;
use Formvex\Spoke\Http\RecoveryHoldSubscriber;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RecoveryHoldBoundaryTest extends TestCase
{
    public function testRecoveryHoldBlocksApiRequestsWithoutExposingRestoreDetails(): void
    {
        $event = $this->event('/formvex/api/v1/forms/resolve');
        $this->subscriber()->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('no-store, private', $event->getResponse()?->headers->get('Cache-Control'));
        self::assertSame('nosniff', $event->getResponse()?->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('installation_unavailable', (string) $event->getResponse()?->getContent());
        self::assertStringNotContainsString('/private/root', (string) $event->getResponse()?->getContent());
    }

    public function testRecoveryHoldBlocksPortalRequestsWithPlainSafeMessage(): void
    {
        $event = $this->event('/formvex/maintenance');
        $this->subscriber()->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertSame('This installation is temporarily unavailable while a server-side recovery operation completes.', $event->getResponse()?->getContent());
    }

    public function testUpgradeMaintenanceBlocksPublicApiRequestsWithoutExposingReleaseDetails(): void
    {
        $event = $this->event('/formvex/api/v1/forms/resolve');
        $paths = $this->paths();
        $resolver = new class ($paths) implements SpokeStorageResolver {
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
        };
        $recoveryStore = new class () implements RecoveryHoldStore {
            public function current(PrivateStoragePaths $paths): ?RecoveryHold
            {
                return null;
            }

            public function activate(PrivateStoragePaths $paths, DateTimeImmutable $startedAt, string $operation, string $reason): void
            {
            }

            public function clear(PrivateStoragePaths $paths): void
            {
            }
        };
        $maintenanceStore = new class () implements UpgradeMaintenanceStore {
            public function current(PrivateStoragePaths $paths): ?UpgradeMaintenanceState
            {
                return new UpgradeMaintenanceState('opaque-operation', '1.0.0', '1.0.1', '000016', '000016', 'draining', new DateTimeImmutable('2026-10-02T00:00:00.000000Z'), new DateTimeImmutable('2026-10-02T00:05:00.000000Z'));
            }

            public function begin(PrivateStoragePaths $paths, string $operationId, string $currentRelease, string $targetRelease, string $currentSchema, string $targetSchema, DateTimeImmutable $startedAt, DateTimeImmutable $drainDeadlineAt): UpgradeMaintenanceState
            {
                throw new RuntimeException('not used');
            }

            public function transition(PrivateStoragePaths $paths, UpgradeMaintenanceState $state, string $nextState): UpgradeMaintenanceState
            {
                throw new RuntimeException('not used');
            }

            public function clear(PrivateStoragePaths $paths, string $operationId): void
            {
                throw new RuntimeException('not used');
            }
        };

        new RecoveryHoldSubscriber(new SpokeRuntimeConfiguration('/private/root'), $resolver, $recoveryStore, $maintenanceStore)->onRequest($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(503, $event->getResponse()?->getStatusCode());
        self::assertStringContainsString('installation_unavailable', (string) $event->getResponse()?->getContent());
        self::assertStringNotContainsString('opaque-operation', (string) $event->getResponse()?->getContent());
    }

    private function subscriber(): RecoveryHoldSubscriber
    {
        $paths = $this->paths();
        $resolver = new class ($paths) implements SpokeStorageResolver {
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
        };
        $holdStore = new class () implements RecoveryHoldStore {
            public function current(PrivateStoragePaths $paths): ?RecoveryHold
            {
                return new RecoveryHold('restore', new DateTimeImmutable('2026-10-01T00:00:00.000000Z'), 'private restore test');
            }

            public function activate(PrivateStoragePaths $paths, DateTimeImmutable $startedAt, string $operation, string $reason): void
            {
            }

            public function clear(PrivateStoragePaths $paths): void
            {
            }
        };

        return new RecoveryHoldSubscriber(new SpokeRuntimeConfiguration('/private/root'), $resolver, $holdStore);
    }

    private function event(string $path): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path, 'GET'),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths(
            '/private/root',
            '/private/root/database',
            '/private/root/secrets',
            '/private/root/logs',
            '/private/root/exports',
            '/private/root/diagnostics',
            '/private/root/backups/scheduled',
            '/private/root/backups/manual',
            '/private/root/backups/temporary',
            '/private/root/runtime',
            '/private/root/backups/pre-upgrade',
        );
    }
}
