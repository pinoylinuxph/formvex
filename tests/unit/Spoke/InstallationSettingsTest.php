<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\LoginThrottleSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpEncryption;
use PHPUnit\Framework\TestCase;

final class InstallationSettingsTest extends TestCase
{
    public function testDefaultsUseApprovedSmtpAndThrottleValues(): void
    {
        $settings = InstallationSettings::defaults();

        self::assertSame('Local Spoke', $settings->websiteDisplayName);
        self::assertSame(SmtpEncryption::SMTPS, $settings->smtpEncryption);
        self::assertSame(465, $settings->smtpPort);
        self::assertSame(10, $settings->smtpTimeoutSeconds);
        self::assertSame(5, $settings->loginThrottle->maximumFailures);
        self::assertSame(15, $settings->loginThrottle->windowMinutes);
        self::assertSame(15, $settings->loginThrottle->cooldownMinutes);
    }

    public function testEncryptionModesExposeTheirDefaultPorts(): void
    {
        self::assertSame(465, SmtpEncryption::SMTPS->defaultPort());
        self::assertSame(587, SmtpEncryption::STARTTLS->defaultPort());
        self::assertSame(SmtpEncryption::STARTTLS, SmtpEncryption::fromInput(' STARTTLS '));
    }

    public function testThrottleBoundsAreEnforced(): void
    {
        $this->expectException(InstallationSettingsFailure::class);

        new LoginThrottleSettings(2, 15, 15);
    }
}
