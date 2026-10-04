<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use Formvex\Spoke\Application\InstallationSettings\SmtpDiagnosticsService;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticStatus;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticView;
use Formvex\Spoke\Domain\InstallationSettings\SmtpEncryption;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpDiagnosticsServiceTest extends TestCase
{
    public function testIncompleteSettingsAreNotReadyForTesting(): void
    {
        $view = new SmtpDiagnosticsService()->fromSnapshot(new SettingsSnapshot(
            InstallationSettings::defaults(),
            SmtpTestState::notConfigured(),
            false,
        ));

        self::assertSame(SmtpDiagnosticStatus::NOT_CONFIGURED, $view->status);
        self::assertSame('Not configured', $view->label);
        self::assertFalse($view->testAllowed);
        self::assertStringContainsString('complete the SMTP host', $view->guidance);
    }

    public function testCurrentAndStaleSuccessfulTestsAreDistinguished(): void
    {
        $settings = $this->configuredSettings();
        $completedAt = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $state = new SmtpTestState(SmtpTestStatus::PASSED, $settings->smtpConfigurationRevision, null, 'unsafe provider detail', $completedAt, $completedAt, null);
        $service = new SmtpDiagnosticsService();

        $current = $service->fromSnapshot(new SettingsSnapshot($settings, $state, true));
        self::assertSame(SmtpDiagnosticStatus::PASSED_CURRENT, $current->status);
        self::assertSame('Current test', $current->configurationStatus);
        self::assertStringNotContainsString('unsafe provider detail', $current->explanation);

        $stale = $service->fromSnapshot(new SettingsSnapshot($settings, new SmtpTestState(SmtpTestStatus::PASSED, $settings->smtpConfigurationRevision - 1, null, null, $completedAt, $completedAt, null), true));
        self::assertSame(SmtpDiagnosticStatus::PASSED_STALE, $stale->status);
        self::assertSame('Test required', $stale->configurationStatus);
    }

    #[DataProvider('failureStages')]
    public function testFailureCodesBecomeSafeStageGuidance(string $failureCode, string $stage, string $expectedGuidance): void
    {
        $settings = $this->configuredSettings();
        $completedAt = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $view = new SmtpDiagnosticsService()->fromSnapshot(new SettingsSnapshot(
            $settings,
            new SmtpTestState(SmtpTestStatus::FAILED, $settings->smtpConfigurationRevision, $failureCode, 'raw SMTP response and password', $completedAt, $completedAt, null),
            true,
        ));

        self::assertSame(SmtpDiagnosticStatus::FAILED, $view->status);
        self::assertSame($stage, $view->failureStage);
        self::assertStringContainsString($expectedGuidance, $view->guidance);
        self::assertStringNotContainsString('raw SMTP response', $view->guidance);
        self::assertStringNotContainsString('super-secret', $view->guidance);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function failureStages(): iterable
    {
        yield 'connection' => ['smtp_connection_failed', 'Connection', 'hostname'];
        yield 'timeout' => ['smtp_timeout', 'Connection', 'timeout'];
        yield 'tls' => ['smtp_tls_failed', 'TLS', 'SMTPS or STARTTLS'];
        yield 'authentication' => ['smtp_authentication_failed', 'Authentication', 'username'];
        yield 'recipient' => ['smtp_recipient_rejected', 'Envelope', 'test recipient'];
        yield 'unknown' => ['unknown_failure', 'SMTP test', 'SMTP host'];
    }

    public function testUncertainResultWarnsAboutPossibleDuplicate(): void
    {
        $settings = $this->configuredSettings();
        $completedAt = new DateTimeImmutable('2026-10-04T10:00:00Z');
        $view = new SmtpDiagnosticsService()->fromSnapshot(new SettingsSnapshot(
            $settings,
            new SmtpTestState(SmtpTestStatus::UNCERTAIN, $settings->smtpConfigurationRevision, 'smtp_result_uncertain', 'provider detail', $completedAt, $completedAt, null),
            true,
        ));

        self::assertSame(SmtpDiagnosticStatus::UNCERTAIN, $view->status);
        self::assertSame('Uncertain result', $view->label);
        self::assertStringContainsString('duplicate', $view->guidance);
    }

    public function testUnavailableViewCannotSendATest(): void
    {
        $view = SmtpDiagnosticView::unavailable();

        self::assertSame(SmtpDiagnosticStatus::UNAVAILABLE, $view->status);
        self::assertFalse($view->testAllowed);
        self::assertStringContainsString('No SMTP test was sent', $view->guidance);
    }

    private function configuredSettings(): InstallationSettings
    {
        return InstallationSettings::defaults()->withSmtp(
            'sender@example.com',
            'Sender',
            'smtp.example.com',
            465,
            SmtpEncryption::SMTPS,
            'sender@example.com',
            10,
            'a',
        );
    }
}
