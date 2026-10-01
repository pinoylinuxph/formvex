<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryWarning
{
    public function __construct(
        public string $severity,
        public string $title,
        public string $message,
    ) {
    }
}
