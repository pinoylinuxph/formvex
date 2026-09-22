<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

use DateTimeImmutable;

final readonly class SessionRecord
{
    public function __construct(
        public string $sessionIdHash,
        public string $csrfTokenHash,
        public int $sessionInvalidationGeneration,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActivityAt,
        public DateTimeImmutable $expiresAt,
        public bool $revoked,
        public bool $mustChangePassword,
    ) {
    }
}
