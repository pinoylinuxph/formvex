<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\PreflightCheck;

interface HostingCapabilityProbe
{
    /**
     * @return list<PreflightCheck>
     */
    public function check(InstallationConfiguration $configuration): array;
}
