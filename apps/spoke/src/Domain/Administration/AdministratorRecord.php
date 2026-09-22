<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

use DateTimeImmutable;

final readonly class AdministratorRecord
{
    public function __construct(
        public string $loginIdentifier,
        public string $passwordHash,
        public bool $mustChangePassword,
        public int $sessionInvalidationGeneration,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $passwordChangedAt,
        public ?DateTimeImmutable $lastLoginAt,
    ) {
    }
}
