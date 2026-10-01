<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Branding;

use Formvex\Spoke\Domain\Branding\BrandingSettings;

final readonly class BrandingSaveResult
{
    public function __construct(
        public BrandingSettings $settings,
        public bool $cleanupWarning = false,
    ) {
    }
}
