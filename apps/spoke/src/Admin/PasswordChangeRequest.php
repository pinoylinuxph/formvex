<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

final readonly class PasswordChangeRequest
{
    public function __construct(
        public string $newPassword,
        public string $confirmation,
        public string $csrfToken,
    ) {
    }
}
