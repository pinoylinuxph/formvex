<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;

final class FixedClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
    }
}
