<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation\Exception;

use RuntimeException;

final class FormActivationFailure extends RuntimeException
{
    /** @param array<string, array{code: string, message: string}> $fieldErrors */
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }
}
