<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding\Contract;

use Formvex\Spoke\Domain\Branding\StagedBrandingAsset;
use Formvex\Spoke\Domain\Branding\ValidatedBrandingAsset;

interface BrandingAssetValidator
{
    public function validate(StagedBrandingAsset $asset): ValidatedBrandingAsset;
}
