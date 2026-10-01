<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\StorageSettings;

interface StorageSettingsRepository
{
    public function get(PrivateStoragePaths $paths): StorageSettings;

    public function save(PrivateStoragePaths $paths, StorageSettings $settings, DateTimeImmutable $now): void;
}
