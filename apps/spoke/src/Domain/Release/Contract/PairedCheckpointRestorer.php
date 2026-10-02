<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

interface PairedCheckpointRestorer
{
    public function restore(string $applicationRoot, string $archivePath): void;
}
