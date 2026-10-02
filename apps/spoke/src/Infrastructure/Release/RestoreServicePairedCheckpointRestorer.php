<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Application\Backup\RestoreService;
use Formvex\Spoke\Domain\Release\Contract\PairedCheckpointRestorer;

final readonly class RestoreServicePairedCheckpointRestorer implements PairedCheckpointRestorer
{
    public function __construct(private RestoreService $restoreService)
    {
    }

    public function restore(string $applicationRoot, string $archivePath): void
    {
        $this->restoreService->restore($applicationRoot, $archivePath, true);
    }
}
