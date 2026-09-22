<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Administration\Contract\PasswordHasher;
use RuntimeException;
use Throwable;

final class NativePasswordHasher implements PasswordHasher
{
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function hash(string $password): string
    {
        try {
            return password_hash($password, PASSWORD_DEFAULT);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Unable to create a password hash.', 0, $throwable);
        }
    }

    public function verify(string $password, string $passwordHash): bool
    {
        return password_verify($password, $passwordHash);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return password_needs_rehash($passwordHash, PASSWORD_DEFAULT);
    }

    public function dummyHash(): string
    {
        return self::DUMMY_HASH;
    }
}
