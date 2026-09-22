<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Installation;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
