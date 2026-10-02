<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

final readonly class ReleaseOperationResult
{
    public function __construct(
        public string $operationId,
        public string $releaseVersion,
        public string $schemaVersion,
        public string $backupId,
    ) {
    }
}
