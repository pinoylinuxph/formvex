<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Contract\Clock;

final class AdjustableClock implements Clock
{
    private DateTimeImmutable $current;

    public function __construct()
    {
        $this->current = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->current = $this->current->add(new DateInterval('PT' . $seconds . 'S'));
    }
}
