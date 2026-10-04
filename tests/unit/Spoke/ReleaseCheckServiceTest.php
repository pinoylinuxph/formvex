<?php

declare(strict_types=1);

namespace FormvexTests\Unit\Spoke;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Application\Release\ReleaseCheckService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckLock;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckRepository;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataClient;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseCheckSettings;
use Formvex\Spoke\Domain\Release\ReleaseCheckState;
use Formvex\Spoke\Domain\Release\ReleaseMetadata;
use Formvex\Spoke\Infrastructure\Release\CurrentReleaseVersionReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReleaseCheckServiceTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    private ReleaseCheckTestRepository $repository;

    private ReleaseCheckTestMetadataClient $client;

    private ReleaseCheckTestClock $clock;

    private ReleaseCheckTestLock $lock;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-release-service-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0o700, true);
        file_put_contents($this->root . '/RELEASE-MANIFEST.json', '{"release_version":"1.0.0"}');
        $this->paths = new PrivateStoragePaths($this->root, $this->root, $this->root, $this->root, $this->root, $this->root, $this->root, $this->root, $this->root, $this->root);
        $this->repository = new ReleaseCheckTestRepository();
        $this->client = new ReleaseCheckTestMetadataClient();
        $this->clock = new ReleaseCheckTestClock(new DateTimeImmutable('2026-10-04T00:00:00Z'));
        $this->lock = new ReleaseCheckTestLock();
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/RELEASE-MANIFEST.json');
        rmdir($this->root);
    }

    public function testDisabledCheckDoesNotContactTheMetadataSource(): void
    {
        $state = $this->service()->run($this->root);

        self::assertSame('disabled', $state->status);
        self::assertSame(0, $this->client->fetchCount);
        self::assertSame('disabled', $this->repository->state($this->paths)->status);
    }

    public function testDailyCheckIsIdempotentUntilForced(): void
    {
        $this->repository->enabled = true;
        $this->client->metadata = $this->metadata('1.0.0', 'normal');

        self::assertSame('current', $this->service()->run($this->root)->status);
        $this->clock->advance('+1 hour');
        self::assertSame('current', $this->service()->run($this->root)->status);
        self::assertSame(1, $this->client->fetchCount);

        self::assertSame('current', $this->service()->run($this->root, true)->status);
        self::assertSame(2, $this->client->fetchCount);
    }

    public function testAvailableReleaseCanBeAcknowledgedAndSeverityChangeClearsAcknowledgement(): void
    {
        $this->repository->enabled = true;
        $this->client->metadata = $this->metadata('1.1.0', 'important');
        $state = $this->service()->run($this->root, true);

        self::assertSame('available', $state->status);
        self::assertTrue($state->canAcknowledge());
        $this->service()->acknowledge($this->root, '1.1.0', 'admin');
        self::assertFalse($this->repository->state($this->paths)->noticeVisible());

        $this->client->metadata = $this->metadata('1.1.0', 'critical');
        $changed = $this->service()->run($this->root, true);

        self::assertSame('critical', $changed->severity);
        self::assertNull($changed->acknowledgedVersion);
        self::assertTrue($changed->noticeVisible());
    }

    public function testFailurePreservesLastSafeProjectionAndBecomesStaleAfterFortyEightHours(): void
    {
        $this->repository->enabled = true;
        $this->client->metadata = $this->metadata('1.1.0', 'normal');
        $successful = $this->service()->run($this->root, true);
        $this->client->failure = new ReleaseCheckFailure('metadata_transport_failed', 'The metadata source timed out.');
        $this->clock->advance('+1 day');
        $failed = $this->service()->run($this->root, true);

        self::assertSame('failed', $failed->status);
        self::assertSame($successful->availableVersion, $failed->availableVersion);
        self::assertSame($successful->lastSuccessfulAt?->format('c'), $failed->lastSuccessfulAt?->format('c'));

        $this->clock->advance('+2 days');
        self::assertSame('stale', $failed->displayStatus($this->clock->now()));
    }

    public function testCurrentStateBecomesStaleAndRecoversAfterASuccessfulRefresh(): void
    {
        $this->repository->enabled = true;
        $this->client->metadata = $this->metadata('1.0.0', 'normal');
        $current = $this->service()->run($this->root, true);

        self::assertSame('current', $current->status);
        $this->clock->advance('+3 days');
        self::assertSame('stale', $this->service()->state($this->root)->displayStatus($this->clock->now()));

        $recovered = $this->service()->run($this->root, true);

        self::assertSame('current', $recovered->status);
        self::assertSame('current', $recovered->displayStatus($this->clock->now()));
        self::assertSame($this->clock->now()->format('c'), $recovered->lastSuccessfulAt?->format('c'));
    }

    public function testFailedStateRecoversToCurrentAfterTheSourceBecomesAvailable(): void
    {
        $this->repository->enabled = true;
        $this->client->metadata = $this->metadata('1.0.0', 'normal');
        $this->service()->run($this->root, true);

        $this->client->failure = new ReleaseCheckFailure('metadata_transport_failed', 'The metadata source timed out.');
        $failed = $this->service()->run($this->root, true);
        self::assertSame('failed', $failed->status);
        self::assertSame('metadata_transport_failed', $failed->failureCode);

        $recovered = $this->service()->run($this->root, true);

        self::assertSame('current', $recovered->status);
        self::assertNull($recovered->failureCode);
        self::assertNull($recovered->failureMessage);
    }

    public function testConcurrentCheckReturnsSafeInProgressStateWithoutContactingSource(): void
    {
        $this->repository->enabled = true;
        $this->lock->failure = new InstallationFailure('release_check_in_progress');

        $state = $this->service()->run($this->root, true);

        self::assertSame('check_in_progress', $state->status);
        self::assertSame(0, $this->client->fetchCount);
    }

    private function service(): ReleaseCheckService
    {
        return new ReleaseCheckService(
            new ReleaseCheckTestResolver($this->paths),
            $this->repository,
            $this->client,
            $this->lock,
            new CurrentReleaseVersionReader($this->root),
            $this->clock,
        );
    }

    private function metadata(string $version, string $severity): ReleaseMetadata
    {
        return new ReleaseMetadata(
            $version,
            $severity,
            true,
            '1.0.0',
            'https://updates.example.com/releases/' . $version,
            'https://updates.example.com/packages/' . $version . '.zip',
            str_repeat('a', 64),
            $this->clock->now(),
        );
    }
}

final class ReleaseCheckTestResolver implements SpokeStorageResolver
{
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
}

final class ReleaseCheckTestRepository implements ReleaseCheckRepository
{
    public bool $enabled = false;

    private ?ReleaseCheckState $storedState = null;

    public function settings(PrivateStoragePaths $paths): ReleaseCheckSettings
    {
        return new ReleaseCheckSettings($this->enabled, new DateTimeImmutable('2026-10-04T00:00:00Z'));
    }

    public function saveSettings(PrivateStoragePaths $paths, bool $enabled, DateTimeImmutable $now): void
    {
        $this->enabled = $enabled;
    }

    public function state(PrivateStoragePaths $paths): ReleaseCheckState
    {
        return $this->storedState ?? ReleaseCheckState::initial('1.0.0', new DateTimeImmutable('2026-10-04T00:00:00Z'));
    }

    public function saveState(PrivateStoragePaths $paths, ReleaseCheckState $state, DateTimeImmutable $now): void
    {
        $this->storedState = $state;
    }

    public function acknowledge(PrivateStoragePaths $paths, string $releaseVersion, string $actor, DateTimeImmutable $now): void
    {
        $state = $this->state($paths);
        if ($state->status !== 'available' || $state->availableVersion !== $releaseVersion || !in_array($state->severity, ['normal', 'important'], true)) {
            throw new RuntimeException('release_acknowledgement_invalid');
        }
        $this->storedState = new ReleaseCheckState($state->status, $state->currentVersion, $state->availableVersion, $state->severity, $state->minimumSupportedVersion, $state->releaseNotesUrl, $state->packageUrl, $state->packageSha256, $state->publishedAt, $state->lastAttemptAt, $state->lastSuccessfulAt, $state->failureCode, $state->failureMessage, $releaseVersion, $now);
    }
}

final class ReleaseCheckTestMetadataClient implements ReleaseMetadataClient
{
    public int $fetchCount = 0;

    public ?ReleaseMetadata $metadata = null;

    public ?ReleaseCheckFailure $failure = null;

    public function fetch(): ReleaseMetadata
    {
        ++$this->fetchCount;
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }
        if ($this->metadata === null) {
            throw new RuntimeException('test_metadata_missing');
        }

        return $this->metadata;
    }
}

final class ReleaseCheckTestLock implements ReleaseCheckLock
{
    public ?InstallationFailure $failure = null;

    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }

        return new ReleaseCheckTestStorageLock();
    }
}

final class ReleaseCheckTestStorageLock implements StorageLock
{
    public function release(): void
    {
    }
}

final class ReleaseCheckTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $interval): void
    {
        $this->current = $this->current->modify($interval);
    }
}
