<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\PasswordPolicy;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Filesystem\LocalSpokeStorageResolver;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoLocalAdministratorStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Infrastructure\Security\NativePasswordHasher;
use Formvex\Spoke\Infrastructure\Security\NativeSecurityTokenGenerator;
use Formvex\Spoke\Infrastructure\Security\NativeTemporaryPasswordGenerator;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use PHPUnit\Framework\TestCase;

final class LocalAdministratorServiceTest extends TestCase
{
    private string $temporaryRoot;

    private AdjustableClock $clock;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-admin-' . bin2hex(random_bytes(8));
        $this->clock = new AdjustableClock();
        mkdir($this->temporaryRoot, 0o700, true);

        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/pre-upgrade', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }

        $paths = $this->paths();
        $store = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $this->clock,
            ),
            $this->clock,
            new FixedIdentifierGenerator(),
        );
        $store->initialize($paths);
        file_put_contents($paths->markerFile(), '{"installation_id":"0195f2b8-7c3a-7f42-8c11-4ac3b865e092","schema_version":"000002"}');
        chmod($paths->markerFile(), 0o600);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testBootstrapFirstLoginPasswordChangeAndResetInvalidateSessions(): void
    {
        $service = $this->service();
        $bootstrap = $service->bootstrap($this->temporaryRoot);

        self::assertGreaterThanOrEqual(32, strlen($bootstrap->temporaryPassword));

        $firstSession = $service->authenticate($this->temporaryRoot, 'admin', $bootstrap->temporaryPassword, '127.0.0.1');
        self::assertTrue($firstSession->mustChangePassword);
        self::assertNotNull($service->session($this->temporaryRoot, $firstSession->sessionId));

        $secondSession = $service->changePassword(
            $this->temporaryRoot,
            $firstSession->sessionId,
            $firstSession->csrfToken,
            'correct horse battery staple',
        );
        self::assertFalse($secondSession->mustChangePassword);
        self::assertNull($service->session($this->temporaryRoot, $firstSession->sessionId));

        $reset = $service->reset($this->temporaryRoot);
        self::assertNull($service->session($this->temporaryRoot, $secondSession->sessionId));

        $resetSession = $service->authenticate($this->temporaryRoot, 'admin', $reset->temporaryPassword, '127.0.0.1');
        self::assertTrue($resetSession->mustChangePassword);
    }

    public function testLoginThrottleStartsAfterFiveFailures(): void
    {
        $service = $this->service();
        $bootstrap = $service->bootstrap($this->temporaryRoot);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->authenticate($this->temporaryRoot, 'admin', 'wrong password', '127.0.0.1');
                self::fail('The invalid password should be rejected.');
            } catch (AdministratorFailure $failure) {
                self::assertSame('invalid_credentials', $failure->failureCode);
            }
        }

        try {
            $service->authenticate($this->temporaryRoot, 'admin', $bootstrap->temporaryPassword, '127.0.0.1');
            self::fail('The cooldown should reject the correct password.');
        } catch (AdministratorFailure $failure) {
            self::assertSame('login_rate_limited', $failure->failureCode);
            self::assertSame(900, $failure->retryAfterSeconds);
        }
    }

    public function testPasswordPolicyAllowsPassphrasesAndRejectsShortPasswords(): void
    {
        $policy = new PasswordPolicy();
        $policy->validate('correct horse battery staple');

        $this->expectExceptionMessage('at least 12 characters');
        $policy->validate('short');
    }

    public function testPasswordPolicyRejectsMoreThanSeventyTwoUtf8Bytes(): void
    {
        $this->expectExceptionMessage('too long');
        new PasswordPolicy()->validate(str_repeat('a', 73));
    }

    public function testIdleSessionExpiresAfterThirtyMinutes(): void
    {
        $service = $this->service();
        $bootstrap = $service->bootstrap($this->temporaryRoot);
        $session = $service->authenticate($this->temporaryRoot, 'admin', $bootstrap->temporaryPassword, '127.0.0.1');

        $this->clock->advanceSeconds(1801);

        self::assertNull($service->session($this->temporaryRoot, $session->sessionId));
    }

    private function service(): LocalAdministratorService
    {
        return new LocalAdministratorService(
            new LocalSpokeStorageResolver(),
            new PdoLocalAdministratorStore(),
            new NativePasswordHasher(),
            new NativeSecurityTokenGenerator(),
            new NativeTemporaryPasswordGenerator(),
            new PasswordPolicy(),
            $this->clock,
            new PdoInstallationSettingsStore(),
        );
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths(
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
            $this->temporaryRoot . '/backups/pre-upgrade',
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
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
