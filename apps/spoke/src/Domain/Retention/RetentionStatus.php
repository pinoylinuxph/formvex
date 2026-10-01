<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Retention;

final readonly class RetentionStatus
{
    public function __construct(
        public ?string $lastRunAt,
        public ?string $lastStatus,
        public int $lastScanned,
        public int $lastDeleted,
        public int $lastDeferred,
        public int $lastFailed,
        public ?string $lastErrorCode,
        public ?string $lastSuccessAt = null,
    ) {
    }

    public static function notRun(): self
    {
        return new self(null, null, 0, 0, 0, 0, null);
    }
}
