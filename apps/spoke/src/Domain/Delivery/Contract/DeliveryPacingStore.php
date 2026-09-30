<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DeliveryPacingStore
{
    public function tryConsume(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limitPerMinute): bool;
}
