<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryResendResult
{
    public function __construct(
        public bool $changed,
        public string $message,
        public int $cycleNumber,
    ) {
    }
}
