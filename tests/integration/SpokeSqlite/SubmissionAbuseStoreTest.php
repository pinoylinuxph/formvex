<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoAbuseStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use Formvex\Spoke\Migrations\Version000006CreateSubmissions;
use Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubmissionAbuseStoreTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    private PdoAbuseStore $store;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-abuse-' . bin2hex(random_bytes(8));
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

        $clock = new FixedClock();
        new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $clock,
                new Version000006CreateSubmissions(),
                new Version000007CreateSubmissionAbuse(),
            ),
            $clock,
            new FixedIdentifierGenerator(),
        )->initialize($this->paths);

        $this->store = new PdoAbuseStore();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testRateCountersEnforceConfiguredLimitsAndCanBeCleared(): void
    {
        $settings = SubmissionAbuseSettings::defaults();
        $now = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            self::assertTrue($this->store->consumeAttempt($this->paths, $settings, 'identity', 'form-1', $now)->allowed);
        }

        $limited = $this->store->consumeAttempt($this->paths, $settings, 'identity', 'form-1', $now);
        self::assertFalse($limited->allowed);
        self::assertGreaterThan(0, $limited->retryAfterSeconds);

        $this->store->clearCounters($this->paths);
        self::assertTrue($this->store->consumeAttempt($this->paths, $settings, 'identity', 'form-1', $now)->allowed);
    }

    public function testCaptchaOutageHasInitialReminderAndRecoveryTransitions(): void
    {
        $initial = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
        $opened = $this->store->recordFailure($this->paths, 'provider_timeout', $initial);
        self::assertSame('spoke.abuse.captcha_outage_opened', $opened->event);
        self::assertTrue($opened->state->open);

        $withinHour = $this->store->recordFailure($this->paths, 'provider_timeout', $initial->modify('+30 minutes'));
        self::assertNull($withinHour->event);

        $reminder = $this->store->recordFailure($this->paths, 'provider_timeout', $initial->modify('+61 minutes'));
        self::assertSame('spoke.abuse.captcha_outage_reminder_due', $reminder->event);

        $recovered = $this->store->recordSuccess($this->paths, $initial->modify('+62 minutes'));
        self::assertSame('spoke.abuse.captcha_outage_recovered', $recovered->event);
        self::assertFalse($recovered->state->open);
        self::assertNull($this->store->recordSuccess($this->paths, $initial->modify('+63 minutes'))->event);
    }

    public function testCleanupKeepsRecentBucketsAndDeletesExpiredBuckets(): void
    {
        $settings = SubmissionAbuseSettings::defaults();
        $now = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
        $old = $now->modify('-7201 seconds');

        self::assertTrue($this->store->consumeAttempt($this->paths, $settings, 'old', 'form-1', $old)->allowed);
        self::assertTrue($this->store->consumeAttempt($this->paths, $settings, 'recent', 'form-1', $now)->allowed);
        self::assertSame(3, $this->store->cleanup($this->paths, $settings, $now));

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertSame(3, (int) $connection->query('SELECT COUNT(*) FROM abuse_rate_counters')->fetchColumn());
    }

    public function testSettingsArePersistedWithoutPersistingSecrets(): void
    {
        $settings = new SubmissionAbuseSettings(trustedProxyCidrs: ['192.0.2.0/24']);
        $now = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
        $this->store->save($this->paths, $settings, $now);

        $stored = $this->store->get($this->paths);
        self::assertSame(['192.0.2.0/24'], $stored->trustedProxyCidrs);

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertFalse((bool) $connection->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE '%secret%'")->fetchColumn());
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
