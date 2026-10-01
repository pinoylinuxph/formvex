<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Delivery\DeliveryWorkerResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface WorkerHeartbeatStore
{
    public function recordSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now, DeliveryWorkerResult $result): void;
}
