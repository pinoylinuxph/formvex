<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Application\Release\HealthCheckService;
use Formvex\Spoke\Domain\Release\Contract\ReleaseHealthChecker;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;

final readonly class HealthCheckReleaseChecker implements ReleaseHealthChecker
{
    public function __construct(private HealthCheckService $healthCheck)
    {
    }

    public function assertHealthy(string $applicationRoot): void
    {
        $result = $this->healthCheck->check($applicationRoot, true);
        if (!$result->healthy) {
            throw new ReleaseOperationFailure('health_check_failed', 'The release health checks did not pass. Keep the installation unavailable and use the paired rollback procedure.');
        }
    }
}
