<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpTestTransport;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpEncryption;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestResult;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Infrastructure\Security\LocalSmtpSecretStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallationSettingsServiceTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    private AdjustableClock $clock;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-settings-' . bin2hex(random_bytes(8));
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
        $this->clock = new AdjustableClock();
        $store = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new \Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata(),
                new \Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth(),
                new \Formvex\Spoke\Migrations\Version000003CreateInstallationSettings(),
                new \Formvex\Spoke\Migrations\Version000004CreateFormConfiguration(),
                new \Formvex\Spoke\Migrations\Version000005CreateFormDiscovery(),
                $this->clock,
            ),
            $this->clock,
            new FixedIdentifierGenerator(),
        );
        $store->initialize($this->paths);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testSettingsArePersistedSecretsRemainPrivateAndTestsAreRateLimited(): void
    {
        $transport = new RecordingSmtpTestTransport();
        $service = $this->service($transport);

        $service->saveIdentity($this->temporaryRoot, [
            'website_display_name' => 'Logoslab',
            'bare_domain' => 'logoslab.xyz',
            'www_alias' => 'www.logoslab.xyz',
            'operational_alert_email' => 'admin@logoslab.xyz',
        ]);
        $snapshot = $service->saveSmtp($this->temporaryRoot, [
            'sender_email' => 'forms@logoslab.xyz',
            'sender_name' => 'Logoslab Forms',
            'smtp_host' => 'mail.logoslab.xyz',
            'smtp_port' => '465',
            'smtp_encryption' => 'smtps',
            'smtp_username' => 'forms@logoslab.xyz',
            'smtp_password' => 'private-password',
            'smtp_timeout_seconds' => '10',
        ]);

        self::assertSame('Logoslab', $snapshot->settings->websiteDisplayName);
        self::assertTrue($snapshot->smtpSecretConfigured);
        self::assertFileExists($this->paths->secrets . '/smtp-password-b.php');
        self::assertStringNotContainsString('private-password', file_get_contents($this->paths->databaseFile()) ?: '');

        $service->saveSmtp($this->temporaryRoot, [
            'sender_email' => 'forms@logoslab.xyz',
            'sender_name' => 'Logoslab Forms',
            'smtp_host' => 'mail.logoslab.xyz',
            'smtp_port' => '465',
            'smtp_encryption' => 'smtps',
            'smtp_username' => 'forms@logoslab.xyz',
            'smtp_password' => 'replacement-password',
            'smtp_timeout_seconds' => '10',
        ]);
        self::assertFileDoesNotExist($this->paths->secrets . '/smtp-password-b.php');
        self::assertSame('replacement-password', new LocalSmtpSecretStore()->read($this->paths, 'a'));

        $state = $service->sendSmtpTest($this->temporaryRoot, 'owner@logoslab.xyz');
        self::assertSame('passed', $state->status->value);
        self::assertSame(['owner@logoslab.xyz'], $transport->recipients);

        try {
            $service->sendSmtpTest($this->temporaryRoot, 'owner@logoslab.xyz');
            self::fail('The SMTP test cooldown must reject an immediate repeat test.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('smtp_test_cooldown', $failure->failureCode);
            self::assertGreaterThan(0, $failure->retryAfterSeconds);
        }
    }

    public function testSnapshotDoesNotContactTheSmtpTransport(): void
    {
        $transport = new RecordingSmtpTestTransport();

        $this->service($transport)->snapshot($this->temporaryRoot);

        self::assertSame([], $transport->recipients);
    }

    public function testStateWriteFailureReturnsSafeFailureAfterTransportResult(): void
    {
        $transport = new RecordingSmtpTestTransport();
        $settings = InstallationSettings::defaults()->withSmtp(
            'sender@example.com',
            'Sender',
            'smtp.example.com',
            465,
            SmtpEncryption::SMTPS,
            'sender@example.com',
            10,
            'a',
        );
        $store = new class ($settings) implements InstallationSettingsStore {
            public function __construct(private readonly InstallationSettings $settings)
            {
            }

            public function get(PrivateStoragePaths $paths): InstallationSettings
            {
                return $this->settings;
            }

            public function save(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now): void
            {
            }

            public function getTestState(PrivateStoragePaths $paths): \Formvex\Spoke\Domain\InstallationSettings\SmtpTestState
            {
                return \Formvex\Spoke\Domain\InstallationSettings\SmtpTestState::notConfigured();
            }

            public function saveTestState(PrivateStoragePaths $paths, \Formvex\Spoke\Domain\InstallationSettings\SmtpTestState $state): void
            {
                throw new RuntimeException('raw database failure');
            }

            public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void
            {
            }
        };
        $secretStore = new LocalSmtpSecretStore();
        $secretStore->write($this->paths, 'a', 'private-password');
        $service = new InstallationSettingsService(
            new FixedStorageResolver($this->paths),
            $store,
            $secretStore,
            $transport,
            $this->clock,
        );

        try {
            $service->sendSmtpTest($this->temporaryRoot, 'owner@example.com');
            self::fail('A state-write failure must not report SMTP readiness.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('smtp_test_state_save_failed', $failure->failureCode);
            self::assertStringNotContainsString('raw database failure', $failure->getMessage());
        }

        self::assertSame(['owner@example.com'], $transport->recipients);
    }

    public function testInvalidSmtpInputDoesNotWriteTheReplacementSecret(): void
    {
        $transport = new RecordingSmtpTestTransport();
        $service = $this->service($transport);

        $service->saveSmtp($this->temporaryRoot, [
            'sender_email' => 'forms@example.com',
            'sender_name' => '',
            'smtp_host' => 'mail.example.com',
            'smtp_port' => '465',
            'smtp_encryption' => 'smtps',
            'smtp_username' => 'forms@example.com',
            'smtp_password' => 'first-password',
            'smtp_timeout_seconds' => '10',
        ]);

        try {
            $service->saveSmtp($this->temporaryRoot, [
                'sender_email' => 'not-an-email',
                'sender_name' => '',
                'smtp_host' => 'mail.example.com',
                'smtp_port' => '465',
                'smtp_encryption' => 'smtps',
                'smtp_username' => 'forms@example.com',
                'smtp_password' => 'replacement-password',
                'smtp_timeout_seconds' => '10',
            ]);
            self::fail('Invalid SMTP input must fail before secret rotation.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('sender_email_invalid', $failure->failureCode);
        }

        self::assertSame('first-password', new LocalSmtpSecretStore()->read($this->paths, 'b'));
        self::assertFileDoesNotExist($this->paths->secrets . '/smtp-password-a.php');
    }

    public function testDiscoveryPayloadLimitUsesTheApprovedRangeAndPersistsInBytes(): void
    {
        $service = $this->service(new RecordingSmtpTestTransport());

        $snapshot = $service->saveDiscovery($this->temporaryRoot, ['discovery_payload_limit_kib' => '512']);

        self::assertSame(512 * 1024, $snapshot->settings->discoveryPayloadLimitBytes);

        try {
            $service->saveDiscovery($this->temporaryRoot, ['discovery_payload_limit_kib' => '1025']);
            self::fail('A discovery limit above 1,024 KiB must be rejected.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('discovery_payload_limit_invalid', $failure->failureCode);
        }
    }

    private function service(RecordingSmtpTestTransport $transport): InstallationSettingsService
    {
        return new InstallationSettingsService(
            new FixedStorageResolver($this->paths),
            new PdoInstallationSettingsStore(),
            new LocalSmtpSecretStore(),
            $transport,
            $this->clock,
        );
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

final class FixedStorageResolver implements SpokeStorageResolver
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

final class RecordingSmtpTestTransport implements SmtpTestTransport
{
    /** @var list<string> */
    public array $recipients = [];

    public function send(InstallationSettings $settings, string $recipient): SmtpTestResult
    {
        $this->recipients[] = $recipient;

        return SmtpTestResult::passed();
    }
}
