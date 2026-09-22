<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

interface PasswordHasher
{
    public function hash(string $password): string;

    public function verify(string $password, string $passwordHash): bool;

    public function needsRehash(string $passwordHash): bool;

    public function dummyHash(): string;
}
