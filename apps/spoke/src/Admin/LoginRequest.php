<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

final readonly class LoginRequest
{
    public function __construct(
        public string $loginIdentifier,
        public string $password,
        public string $csrfToken,
    ) {
    }
}
