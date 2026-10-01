<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding\Contract;

use Formvex\Spoke\Domain\Branding\BrandingAsset;
use Formvex\Spoke\Domain\Branding\BrandingUpload;
use Formvex\Spoke\Domain\Branding\StagedBrandingAsset;
use Formvex\Spoke\Domain\Branding\ValidatedBrandingAsset;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface BrandingAssetStore
{
    public function stage(PrivateStoragePaths $paths, BrandingUpload $upload, string $type): StagedBrandingAsset;

    public function publish(StagedBrandingAsset $staged, ValidatedBrandingAsset $validated, int $revision): BrandingAsset;

    public function cleanup(StagedBrandingAsset $staged): void;

    public function remove(?BrandingAsset $asset): bool;

    public function url(?BrandingAsset $asset): ?string;
}
