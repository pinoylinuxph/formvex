<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration\Exception;

use RuntimeException;

final class FormConfigurationFailure extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }
}
