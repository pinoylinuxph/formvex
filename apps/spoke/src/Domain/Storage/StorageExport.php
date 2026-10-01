<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

use DateTimeImmutable;

final readonly class StorageExport
{
    public function __construct(
        public string $publicId,
        public int $rowCount,
        public int $fileSizeBytes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $downloadedAt,
        public string $status,
    ) {
    }

    public function isAvailable(DateTimeImmutable $now): bool
    {
        return $this->status !== 'expired' && $this->expiresAt > $now;
    }
}
