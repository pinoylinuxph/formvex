<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;

final class FixedIdentifierGenerator implements IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return '0195f2b8-7c3a-7f42-8c11-4ac3b865e092';
    }
}
