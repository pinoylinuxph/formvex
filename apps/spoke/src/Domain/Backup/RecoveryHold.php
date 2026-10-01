<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

use DateTimeImmutable;

final readonly class RecoveryHold
{
    public function __construct(
        public string $operation,
        public DateTimeImmutable $startedAt,
        public string $reason,
    ) {
    }
}
