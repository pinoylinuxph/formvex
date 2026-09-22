<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

use DateTimeImmutable;

interface IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string;
}
