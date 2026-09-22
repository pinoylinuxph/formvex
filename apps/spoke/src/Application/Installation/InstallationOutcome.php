<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Installation;

use Formvex\Spoke\Domain\Installation\InstallationIdentity;
use Formvex\Spoke\Domain\Installation\PreflightReport;

final readonly class InstallationOutcome
{
    private function __construct(
        public bool $successful,
        public bool $alreadyInitialized,
        public ?string $failureCode,
        public PreflightReport $preflight,
        public ?InstallationIdentity $identity,
    ) {
    }

    public static function success(
        PreflightReport $preflight,
        InstallationIdentity $identity,
        bool $alreadyInitialized,
    ): self {
        return new self(true, $alreadyInitialized, null, $preflight, $identity);
    }

    public static function failure(PreflightReport $preflight, string $failureCode): self
    {
        return new self(false, false, $failureCode, $preflight, null);
    }
}
