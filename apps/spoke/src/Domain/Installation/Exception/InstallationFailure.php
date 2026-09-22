<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Exception;

use RuntimeException;

final class InstallationFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
