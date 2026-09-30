<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use PHPUnit\Framework\TestCase;

final class SubmissionAbuseSettingsTest extends TestCase
{
    public function testDefaultsUseTheApprovedBoundedLimits(): void
    {
        $settings = SubmissionAbuseSettings::defaults();

        self::assertSame(5, $settings->perFormShortLimit);
        self::assertSame(600, $settings->perFormShortWindowSeconds);
        self::assertSame(20, $settings->perFormHourLimit);
        self::assertSame(30, $settings->installationHourLimit);
        self::assertSame(60, $settings->floodMinuteLimit);
        self::assertSame(300, $settings->floodHourLimit);
    }

    public function testProxyCidrsAreNormalizedAndValidated(): void
    {
        $settings = new SubmissionAbuseSettings(trustedProxyCidrs: ['203.0.113.0/24', '2001:DB8::/32', '203.0.113.0/24']);

        self::assertSame(['2001:db8::/32', '203.0.113.0/24'], $settings->normalizedTrustedProxyCidrs());
    }

    public function testInvalidProxyCidrAndOutOfRangeLimitAreRejected(): void
    {
        try {
            new SubmissionAbuseSettings(trustedProxyCidrs: ['203.0.113.0/33']);
            self::fail('An invalid CIDR must be rejected.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('trusted_proxy_cidrs_invalid', $failure->failureCode);
        }

        $this->expectException(InstallationSettingsFailure::class);
        new SubmissionAbuseSettings(perFormShortLimit: 0);
    }
}
