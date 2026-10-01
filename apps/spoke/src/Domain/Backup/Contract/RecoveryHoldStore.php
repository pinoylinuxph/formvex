<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Backup\RecoveryHold;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface RecoveryHoldStore
{
    public function current(PrivateStoragePaths $paths): ?RecoveryHold;

    public function activate(PrivateStoragePaths $paths, DateTimeImmutable $startedAt, string $operation, string $reason): void;

    public function clear(PrivateStoragePaths $paths): void;
}
