<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

interface ReleaseHealthChecker
{
    public function assertHealthy(string $applicationRoot): void;
}
