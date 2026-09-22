<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Installation;

use InvalidArgumentException;

final readonly class SpokeRuntimeConfiguration
{
    public function __construct(public string $applicationRoot)
    {
        if ($this->applicationRoot === '' || !str_starts_with($this->applicationRoot, DIRECTORY_SEPARATOR) || str_contains($this->applicationRoot, "\0") || str_contains($this->applicationRoot, '..')) {
            throw new InvalidArgumentException('The Formvex application root is invalid.');
        }
    }
}
