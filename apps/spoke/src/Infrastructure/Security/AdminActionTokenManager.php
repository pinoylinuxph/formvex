<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use RuntimeException;

final readonly class AdminActionTokenManager
{
    public function __construct(
        private SecurityTokenGenerator $tokenGenerator,
        private string $applicationSecret,
    ) {
        if ($this->applicationSecret === '') {
            throw new RuntimeException('The application secret is required.');
        }
    }

    public function issue(string $operation, string $resourceId): string
    {
        $nonce = $this->tokenGenerator->generate();
        $signature = hash_hmac('sha256', $this->message($nonce, $operation, $resourceId), $this->applicationSecret);

        return $nonce . '.' . $signature;
    }

    public function isValid(string $token, string $operation, string $resourceId): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $this->message($parts[0], $operation, $resourceId), $this->applicationSecret);

        return hash_equals($expected, $parts[1]);
    }

    private function message(string $nonce, string $operation, string $resourceId): string
    {
        return $nonce . "\0" . $operation . "\0" . $resourceId;
    }
}
