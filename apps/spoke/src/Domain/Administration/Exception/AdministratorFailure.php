<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Exception;

use RuntimeException;

final class AdministratorFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($failureCode);
    }
}
