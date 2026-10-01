<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding;

final readonly class BrandingUpload
{
    public function __construct(
        public string $path,
        public string $originalName,
        public int $error,
    ) {
    }
}
