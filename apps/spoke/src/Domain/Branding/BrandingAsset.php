<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding;

final readonly class BrandingAsset
{
    public function __construct(
        public string $type,
        public string $filename,
        public string $mediaType,
        public string $sha256,
        public int $bytes,
        public ?int $width,
        public ?int $height,
    ) {
    }
}
