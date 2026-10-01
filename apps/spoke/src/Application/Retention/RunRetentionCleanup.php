<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Retention;

final readonly class RunRetentionCleanup
{
    public function __construct(public string $applicationRoot, public int $batchLimit = 100)
    {
    }
}
