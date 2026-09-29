<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

use DateTimeImmutable;

final readonly class DiscoveryAuthorization
{
    public function __construct(
        public string $capabilityId,
        public string $token,
        public string $discoveryUrl,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
