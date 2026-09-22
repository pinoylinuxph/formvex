<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class InstallationInitialization
{
    public function __construct(
        public InstallationIdentity $identity,
        public bool $created,
    ) {
    }
}
