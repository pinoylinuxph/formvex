<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

final readonly class BackupArtifact
{
    public function __construct(
        public string $storageKey,
        public int $sizeBytes,
        public string $sha256,
        public string $schemaVersion,
    ) {
    }
}
