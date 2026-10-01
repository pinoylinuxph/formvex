<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Retention\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\Retention\RetentionCleanupResult;
use Formvex\Spoke\Domain\Retention\RetentionStatus;

interface RetentionRepository
{
    public function cleanup(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now, int $batchLimit): RetentionCleanupResult;

    public function status(PrivateStoragePaths $paths): RetentionStatus;
}
