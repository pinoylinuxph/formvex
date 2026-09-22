<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

interface SecurityTokenGenerator
{
    public function generate(int $bytes = 32): string;

    public function hash(string $token): string;
}
