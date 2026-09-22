<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

final readonly class TemporaryPasswordResult
{
    public function __construct(public string $temporaryPassword)
    {
    }
}
