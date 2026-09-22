<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Application\Installation\RunInstallationPreflight;
use Formvex\Spoke\Domain\Installation\Contract\HostingCapabilityProbe;
use Formvex\Spoke\Domain\Installation\Contract\PrivateStorage;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\InstallationMarker;
use Formvex\Spoke\Domain\Installation\PreflightCheck;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PreflightReportTest extends TestCase
{
    public function testPreflightPreservesCapabilityThenStorageOrderAndDetectsFailure(): void
    {
        $configuration = InstallationConfiguration::fromOperatorInput('/srv/formvex', '/srv/public', null);
        $probe = new class () implements HostingCapabilityProbe {
            public function check(InstallationConfiguration $configuration): array
            {
                return [PreflightCheck::pass('runtime', 'Runtime is available.')];
            }
        };
        $storage = new class () implements PrivateStorage {
            public function check(InstallationConfiguration $configuration): array
            {
                return [PreflightCheck::fail('private_storage', 'Private storage is unavailable.')];
            }

            public function prepare(InstallationConfiguration $configuration): PrivateStoragePaths
            {
                throw new LogicException('Not used in this test.');
            }

            public function acquireLock(PrivateStoragePaths $paths): StorageLock
            {
                throw new LogicException('Not used in this test.');
            }

            public function readMarker(PrivateStoragePaths $paths): ?InstallationMarker
            {
                throw new LogicException('Not used in this test.');
            }

            public function writeMarker(PrivateStoragePaths $paths, InstallationMarker $marker): void
            {
                throw new LogicException('Not used in this test.');
            }
        };

        $report = new RunInstallationPreflight($probe, $storage)->execute($configuration);

        self::assertSame(['runtime', 'private_storage'], array_map(
            static fn (PreflightCheck $check): string => $check->code,
            $report->checks,
        ));
        self::assertTrue($report->hasBlockingFailures());
    }
}
