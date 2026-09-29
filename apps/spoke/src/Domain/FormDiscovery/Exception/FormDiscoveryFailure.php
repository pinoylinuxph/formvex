<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery\Exception;

use RuntimeException;

final class FormDiscoveryFailure extends RuntimeException
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
