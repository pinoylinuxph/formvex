<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

interface TemporaryPasswordGenerator
{
    public function generate(): string;
}
