<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

final readonly class StorageCapacityDecision
{
    public function __construct(
        public bool $allowed,
        public string $code = 'available',
    ) {
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function rejected(string $code): self
    {
        return new self(false, $code);
    }
}
