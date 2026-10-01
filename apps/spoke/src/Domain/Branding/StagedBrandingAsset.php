<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding;

final readonly class StagedBrandingAsset
{
    public function __construct(
        public string $path,
        public string $type,
        public string $originalName,
    ) {
    }
}
