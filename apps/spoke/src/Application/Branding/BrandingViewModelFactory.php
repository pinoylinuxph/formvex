<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Branding;

use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;

final readonly class BrandingViewModelFactory
{
    public function __construct(
        private BrandingService $brandingService,
        private SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    /** @return array{brandName: string, slogan: string, sloganVisible: bool, logoUrl: ?string, faviconUrl: ?string, brandMark: string, assetRevision: int} */
    public function create(): array
    {
        return $this->brandingService->viewModel($this->runtimeConfiguration->applicationRoot);
    }
}
