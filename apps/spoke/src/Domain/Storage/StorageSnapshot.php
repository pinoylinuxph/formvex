<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

final readonly class StorageSnapshot
{
    public function __construct(
        public StorageSettings $settings,
        public StorageUsage $usage,
    ) {
    }
}
