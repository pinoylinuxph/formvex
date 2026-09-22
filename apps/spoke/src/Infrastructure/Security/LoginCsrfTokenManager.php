<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use RuntimeException;

final readonly class LoginCsrfTokenManager
{
    public function __construct(
        private SecurityTokenGenerator $tokenGenerator,
        private string $applicationSecret,
    ) {
        if ($this->applicationSecret === '') {
            throw new RuntimeException('The application secret is required.');
        }
    }

    public function issue(): string
    {
        $nonce = $this->tokenGenerator->generate();
        $signature = hash_hmac('sha256', $nonce, $this->applicationSecret);

        return $nonce . '.' . $signature;
    }

    public function isValid(string $token): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $parts[0], $this->applicationSecret);

        return hash_equals($expected, $parts[1]);
    }
}
