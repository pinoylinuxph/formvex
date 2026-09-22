<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Administration\Contract\TemporaryPasswordGenerator;
use RuntimeException;

final class NativeTemporaryPasswordGenerator implements TemporaryPasswordGenerator
{
    public function generate(): string
    {
        $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        if (strlen($password) < 32) {
            throw new RuntimeException('Unable to create a temporary password.');
        }

        return $password;
    }
}
