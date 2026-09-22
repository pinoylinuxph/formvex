<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Installation;

use Formvex\Spoke\Domain\Installation\Contract\HostingCapabilityProbe;
use Formvex\Spoke\Domain\Installation\Contract\PrivateStorage;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\PreflightReport;

final readonly class RunInstallationPreflight
{
    public function __construct(
        private HostingCapabilityProbe $hostingCapabilityProbe,
        private PrivateStorage $privateStorage,
    ) {
    }

    public function execute(InstallationConfiguration $configuration): PreflightReport
    {
        return new PreflightReport([
            ...$this->hostingCapabilityProbe->check($configuration),
            ...$this->privateStorage->check($configuration),
        ]);
    }
}
