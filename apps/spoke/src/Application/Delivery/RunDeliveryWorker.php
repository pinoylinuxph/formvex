<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Delivery;

final readonly class RunDeliveryWorker
{
    public function __construct(public int $batchLimit = 10)
    {
    }
}
