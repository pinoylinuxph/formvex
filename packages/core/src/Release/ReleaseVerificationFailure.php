<?php

declare(strict_types=1);

namespace Formvex\Core\Release;

use RuntimeException;

final class ReleaseVerificationFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
