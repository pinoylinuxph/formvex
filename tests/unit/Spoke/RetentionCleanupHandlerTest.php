<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Application\Retention\RunRetentionCleanup;
use Formvex\Spoke\Application\Retention\RunRetentionCleanupHandler;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;
use Formvex\Spoke\Domain\Retention\Contract\RetentionLock;
use Formvex\Spoke\Domain\Retention\Contract\RetentionRepository;
use Formvex\Spoke\Domain\Retention\RetentionCleanupResult;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetentionCleanupHandlerTest extends TestCase
{
    public function testConcurrentRunReturnsLockedWithoutCallingRepository(): void
    {
        $paths = $this->paths();
        $repository = $this->createMock(RetentionRepository::class);
        $repository->expects(self::never())->method('cleanup');
        $handler = new RunRetentionCleanupHandler(
            $this->resolver($paths),
            new LockedRetentionLock(),
            $this->settingsStore(),
            $repository,
            $this->clock(),
        );

        $result = $handler->handle(new RunRetentionCleanup($paths->applicationRoot));

        self::assertSame('locked', $result->status);
        self::assertTrue($result->succeeded);
    }

    public function testRepositoryFailureReturnsNonzeroResultAndReleasesLock(): void
    {
        $paths = $this->paths();
        $lock = new RecordingLock();
        $repository = $this->createStub(RetentionRepository::class);
        $repository->method('cleanup')->willThrowException(new RuntimeException('database failure'));
        $handler = new RunRetentionCleanupHandler(
            $this->resolver($paths),
            new RecordingRetentionLock($lock),
            $this->settingsStore(),
            $repository,
            $this->clock(),
        );

        $result = $handler->handle(new RunRetentionCleanup($paths->applicationRoot));

        self::assertSame('partial_failure', $result->status);
        self::assertFalse($result->succeeded);
        self::assertSame('cleanup_failed', $result->errorCode);
        self::assertTrue($lock->released);
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths('/tmp/formvex-test', '/tmp/formvex-test/database', '/tmp/formvex-test/secrets', '/tmp/formvex-test/logs', '/tmp/formvex-test/exports', '/tmp/formvex-test/diagnostics', '/tmp/formvex-test/backups/scheduled', '/tmp/formvex-test/backups/manual', '/tmp/formvex-test/backups/temporary', '/tmp/formvex-test/runtime');
    }

    private function resolver(PrivateStoragePaths $paths): SpokeStorageResolver
    {
        return new class ($paths) implements SpokeStorageResolver {
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
    }

    private function settingsStore(): InstallationSettingsStore
    {
        return new class () implements InstallationSettingsStore {
            public function get(PrivateStoragePaths $paths): InstallationSettings
            {
                return InstallationSettings::defaults();
            }

            public function save(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now): void
            {
            }

            public function getTestState(PrivateStoragePaths $paths): SmtpTestState
            {
                return SmtpTestState::notConfigured();
            }

            public function saveTestState(PrivateStoragePaths $paths, SmtpTestState $state): void
            {
            }

            public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void
            {
            }
        };
    }

    private function clock(): Clock
    {
        return new class () implements Clock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
            }
        };
    }
}

final class LockedRetentionLock implements RetentionLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        throw new InstallationFailure('installation_in_progress');
    }
}

final class RecordingRetentionLock implements RetentionLock
{
    public function __construct(private readonly StorageLock $lock)
    {
    }

    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        return $this->lock;
    }
}

final class RecordingLock implements StorageLock
{
    public bool $released = false;

    public function release(): void
    {
        $this->released = true;
    }
}
