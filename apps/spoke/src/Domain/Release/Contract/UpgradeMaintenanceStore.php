<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\UpgradeMaintenanceState;

interface UpgradeMaintenanceStore
{
    public function current(PrivateStoragePaths $paths): ?UpgradeMaintenanceState;

    public function begin(PrivateStoragePaths $paths, string $operationId, string $currentRelease, string $targetRelease, string $currentSchema, string $targetSchema, DateTimeImmutable $startedAt, DateTimeImmutable $drainDeadlineAt): UpgradeMaintenanceState;

    public function transition(PrivateStoragePaths $paths, UpgradeMaintenanceState $state, string $nextState): UpgradeMaintenanceState;

    public function clear(PrivateStoragePaths $paths, string $operationId): void;
}
