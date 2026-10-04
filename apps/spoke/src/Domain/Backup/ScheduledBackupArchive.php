<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

use DateTimeImmutable;

final readonly class ScheduledBackupArchive
{
    public function __construct(
        public string $publicId,
        public string $duePeriod,
        public BackupState $state,
        public string $storageKey,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $completedAt,
        public int $sizeBytes,
        public ?string $sha256,
        public string $schemaVersion,
        public int $formatVersion,
        public ?string $failureCode,
        public int $activeDownloads,
        public int $downloadCount,
        public ?DateTimeImmutable $lastDownloadAt,
    ) {
    }

    public function isDownloadable(): bool
    {
        return $this->state === BackupState::VERIFIED;
    }
}
