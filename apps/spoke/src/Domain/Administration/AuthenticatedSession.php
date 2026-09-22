<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

final readonly class AuthenticatedSession
{
    public function __construct(
        public string $sessionId,
        public string $csrfToken,
        public bool $mustChangePassword,
    ) {
    }
}
