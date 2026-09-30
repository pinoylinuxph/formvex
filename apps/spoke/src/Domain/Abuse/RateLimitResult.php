<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $retryAfterSeconds = 0,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function limited(int $retryAfterSeconds): self
    {
        return new self(false, max(1, $retryAfterSeconds));
    }
}
