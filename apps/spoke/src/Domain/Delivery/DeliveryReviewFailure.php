<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

use RuntimeException;
use Throwable;

final class DeliveryReviewFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
