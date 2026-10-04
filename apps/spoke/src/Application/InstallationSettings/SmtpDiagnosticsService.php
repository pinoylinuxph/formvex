<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\InstallationSettings;

use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticStatus;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticView;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;

final class SmtpDiagnosticsService
{
    public function fromSnapshot(SettingsSnapshot $snapshot): SmtpDiagnosticView
    {
        $testAllowed = $snapshot->smtpReady();
        $state = $snapshot->testState;

        if (!$testAllowed) {
            return new SmtpDiagnosticView(
                SmtpDiagnosticStatus::NOT_CONFIGURED,
                'Not configured',
                'warning',
                'The saved SMTP settings are incomplete, so a test message cannot be sent yet.',
                'Open Email delivery Settings, complete the SMTP host, sender address, username, and private password, save the settings, then send an explicit test email.',
                'Test required',
                $state->completedAt,
                null,
                false,
            );
        }

        return match ($state->status) {
            SmtpTestStatus::PASSED => $state->testedRevision === $snapshot->settings->smtpConfigurationRevision
                ? $this->passed($state, $testAllowed)
                : $this->stale($state, $testAllowed),
            SmtpTestStatus::STALE => $this->stale($state, $testAllowed),
            SmtpTestStatus::FAILED => $this->failed($state, $testAllowed),
            SmtpTestStatus::UNCERTAIN => $this->uncertain($state, $testAllowed),
            SmtpTestStatus::NOT_CONFIGURED => new SmtpDiagnosticView(
                SmtpDiagnosticStatus::NOT_CONFIGURED,
                'Not tested',
                'warning',
                'SMTP settings are available, but no successful test has confirmed this configuration.',
                'Send an explicit test email to confirm that the current SMTP configuration is accepted by the provider.',
                'Test required',
                $state->completedAt,
                null,
                $testAllowed,
            ),
        };
    }

    private function passed(SmtpTestState $state, bool $testAllowed): SmtpDiagnosticView
    {
        return new SmtpDiagnosticView(
            SmtpDiagnosticStatus::PASSED_CURRENT,
            'Passed and current',
            'success',
            'The SMTP server accepted the synthetic test message using the current saved configuration.',
            'No SMTP configuration action is required. This confirms SMTP acceptance only; it does not prove inbox placement.',
            'Current test',
            $state->completedAt,
            null,
            $testAllowed,
        );
    }

    private function stale(SmtpTestState $state, bool $testAllowed): SmtpDiagnosticView
    {
        return new SmtpDiagnosticView(
            SmtpDiagnosticStatus::PASSED_STALE,
            'Passed but stale',
            'warning',
            'The previous SMTP test passed, but the saved SMTP settings changed afterward.',
            'Send a new explicit test email before relying on the changed configuration.',
            'Test required',
            $state->completedAt,
            null,
            $testAllowed,
        );
    }

    private function failed(SmtpTestState $state, bool $testAllowed): SmtpDiagnosticView
    {
        [$stage, $label, $guidance] = match ($state->failureCode) {
            'smtp_authentication_failed' => [
                'Authentication',
                'Authentication failed',
                'Verify the SMTP username, password or app password, and the provider authentication policy. Save corrected settings, then send a new explicit test.',
            ],
            'smtp_tls_failed' => [
                'TLS',
                'TLS negotiation failed',
                'Verify the selected SMTPS or STARTTLS mode, port, and certificate requirements against the provider settings. Save corrections, then test again.',
            ],
            'smtp_recipient_rejected' => [
                'Envelope',
                'Recipient rejected',
                'Verify that the test recipient is valid and accepted by the provider. Enter a permitted address and send a new explicit test.',
            ],
            'smtp_timeout' => [
                'Connection',
                'Connection timed out',
                'Verify the SMTP hostname, port, firewall access, timeout, and provider availability. Correct the settings, then test again.',
            ],
            'smtp_connection_failed' => [
                'Connection',
                'Connection failed',
                'Verify the SMTP hostname, port, DNS, firewall access, encryption mode, and provider availability. Correct the settings, then test again.',
            ],
            default => [
                'SMTP test',
                'SMTP test failed',
                'Review the SMTP host, port, encryption mode, username, password, sender address, and timeout in Email delivery Settings, then send a new explicit test.',
            ],
        };

        return new SmtpDiagnosticView(
            SmtpDiagnosticStatus::FAILED,
            $label,
            'danger',
            'The SMTP test did not confirm provider acceptance.',
            $guidance,
            'Test required',
            $state->completedAt,
            $stage,
            $testAllowed,
        );
    }

    private function uncertain(SmtpTestState $state, bool $testAllowed): SmtpDiagnosticView
    {
        return new SmtpDiagnosticView(
            SmtpDiagnosticStatus::UNCERTAIN,
            'Uncertain result',
            'warning',
            'The connection ended after transmission may have occurred, so the provider result cannot be confirmed safely.',
            'Do not assume the message was not sent. Wait for any provider evidence before sending a deliberate new test, because repeating it may create a duplicate.',
            'Test required',
            $state->completedAt,
            'Uncertain',
            $testAllowed,
        );
    }
}
