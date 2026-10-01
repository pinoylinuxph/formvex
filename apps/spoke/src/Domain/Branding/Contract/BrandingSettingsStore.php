<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Branding\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Branding\BrandingSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface BrandingSettingsStore
{
    public function get(PrivateStoragePaths $paths): BrandingSettings;

    public function save(PrivateStoragePaths $paths, BrandingSettings $settings, DateTimeImmutable $now): void;

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $now): void;
}
