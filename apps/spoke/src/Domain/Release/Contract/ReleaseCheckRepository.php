<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\ReleaseCheckSettings;
use Formvex\Spoke\Domain\Release\ReleaseCheckState;

interface ReleaseCheckRepository
{
    public function settings(PrivateStoragePaths $paths): ReleaseCheckSettings;

    public function saveSettings(PrivateStoragePaths $paths, bool $enabled, DateTimeImmutable $now): void;

    public function state(PrivateStoragePaths $paths): ReleaseCheckState;

    public function saveState(PrivateStoragePaths $paths, ReleaseCheckState $state, DateTimeImmutable $now): void;

    public function acknowledge(PrivateStoragePaths $paths, string $releaseVersion, string $actor, DateTimeImmutable $now): void;
}
