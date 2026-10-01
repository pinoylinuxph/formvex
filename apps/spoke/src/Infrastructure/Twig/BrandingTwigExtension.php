<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Twig;

use Formvex\Spoke\Application\Branding\BrandingViewModelFactory;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

final class BrandingTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly BrandingViewModelFactory $viewModelFactory)
    {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['branding' => $this->viewModelFactory->create()];
    }
}
