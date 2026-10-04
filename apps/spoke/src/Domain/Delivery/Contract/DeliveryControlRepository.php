<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Delivery\DeliveryControlStatus;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DeliveryControlRepository
{
    public function status(PrivateStoragePaths $paths): DeliveryControlStatus;

    public function pause(PrivateStoragePaths $paths, string $actor, DateTimeImmutable $now): DeliveryControlStatus;

    public function resume(PrivateStoragePaths $paths, string $actor, DateTimeImmutable $now): DeliveryControlStatus;
}
