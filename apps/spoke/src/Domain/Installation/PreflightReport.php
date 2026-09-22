<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class PreflightReport
{
    /**
     * @param list<PreflightCheck> $checks
     */
    public function __construct(public array $checks)
    {
    }

    public function hasBlockingFailures(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === PreflightCheckStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}
