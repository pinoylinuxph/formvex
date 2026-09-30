<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Submission\Exception;

use RuntimeException;

final class SubmissionFailure extends RuntimeException
{
    /** @param array<string, array{code: string, message: string}> $fieldErrors */
    public function __construct(
        public readonly string $failureCode,
        string $message,
        public readonly array $fieldErrors = [],
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
