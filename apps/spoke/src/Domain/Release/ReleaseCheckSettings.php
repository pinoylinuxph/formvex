<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

use DateTimeImmutable;

final readonly class ReleaseCheckSettings
{
    public function __construct(
        public bool $enabled,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
