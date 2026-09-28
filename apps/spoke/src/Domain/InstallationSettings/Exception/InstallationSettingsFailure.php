<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings\Exception;

use RuntimeException;

final class InstallationSettingsFailure extends RuntimeException
{
    /** @param array<string, string> $fieldErrors */
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly array $fieldErrors = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
