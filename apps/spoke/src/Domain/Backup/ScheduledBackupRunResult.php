<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

final readonly class ScheduledBackupRunResult
{
    public function __construct(
        public string $status,
        public int $created,
        public int $rotated,
        public int $deferred,
        public string $message,
    ) {
    }
}
