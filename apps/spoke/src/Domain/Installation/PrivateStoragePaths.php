<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class PrivateStoragePaths
{
    public function __construct(
        public string $applicationRoot,
        public string $database,
        public string $secrets,
        public string $logs,
        public string $exports,
        public string $diagnostics,
        public string $scheduledBackups,
        public string $manualBackups,
        public string $temporaryBackups,
        public string $runtime,
    ) {
    }

    public function databaseFile(): string
    {
        return $this->database . DIRECTORY_SEPARATOR . 'formvex.sqlite';
    }

    public function markerFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'installation-state.json';
    }

    public function lockFile(): string
    {
        return $this->runtime . DIRECTORY_SEPARATOR . 'installation.lock';
    }
}
