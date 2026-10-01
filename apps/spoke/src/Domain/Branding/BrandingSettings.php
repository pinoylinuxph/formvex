<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding;

final readonly class BrandingSettings
{
    public function __construct(
        public string $brandName,
        public string $slogan,
        public bool $sloganVisible,
        public ?BrandingAsset $logo,
        public ?BrandingAsset $favicon,
        public int $assetRevision,
    ) {
    }

    public static function defaults(): self
    {
        return new self('Noname', '', false, null, null, 1);
    }

    public function withValues(
        string $brandName,
        string $slogan,
        bool $sloganVisible,
        ?BrandingAsset $logo,
        ?BrandingAsset $favicon,
        int $assetRevision,
    ): self {
        return new self($brandName, $slogan, $sloganVisible, $logo, $favicon, $assetRevision);
    }
}
