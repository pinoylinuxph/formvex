<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

use DateTimeImmutable;

final readonly class CaptchaOutageState
{
    public function __construct(
        public bool $open,
        public ?DateTimeImmutable $openedAt,
        public ?DateTimeImmutable $lastFailureAt,
        public ?string $lastFailureCode,
        public ?DateTimeImmutable $lastAlertAt,
        public ?DateTimeImmutable $lastSuccessAt,
    ) {
    }

    public static function clear(): self
    {
        return new self(false, null, null, null, null, null);
    }
}
