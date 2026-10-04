<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormChangeObservation;

use RuntimeException;
use Throwable;

final class FormChangeObservationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
