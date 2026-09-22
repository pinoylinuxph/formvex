<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
