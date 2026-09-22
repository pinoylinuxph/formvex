<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

final readonly class ThemePreferenceRequest
{
    public function __construct(
        public string $csrfToken,
        public string $theme,
        public string $returnRoute,
    ) {
    }
}
