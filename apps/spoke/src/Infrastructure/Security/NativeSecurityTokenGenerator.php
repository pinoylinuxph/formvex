<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use RuntimeException;

final class NativeSecurityTokenGenerator implements SecurityTokenGenerator
{
    public function generate(int $bytes = 32): string
    {
        if ($bytes < 16 || $bytes > 128) {
            throw new RuntimeException('The security token size is invalid.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
