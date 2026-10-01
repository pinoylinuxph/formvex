<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

use RuntimeException;

final class StorageExportFailure extends RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
