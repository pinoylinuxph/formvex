<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstallationConfigurationTest extends TestCase
{
    public function testOperatorInputTrimsValuesAndTreatsEmptyUrlAsAbsent(): void
    {
        $configuration = InstallationConfiguration::fromOperatorInput(
            ' /srv/formvex ',
            ' /srv/public ',
            ' ',
        );

        self::assertSame('/srv/formvex', $configuration->applicationRoot);
        self::assertSame('/srv/public', $configuration->webRoot);
        self::assertNull($configuration->publicBaseUrl);
    }

    public function testNulByteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        InstallationConfiguration::fromOperatorInput("/srv/form\0vex", '/srv/public', null);
    }
}
