<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Diagnostics;

use DateTimeImmutable;

final readonly class DiagnosticReportMetadata
{
    public function __construct(
        public string $publicId,
        public string $actor,
        public string $storageKey,
        public DateTimeImmutable $generatedAt,
        public DateTimeImmutable $expiresAt,
        public int $sizeBytes,
        public string $status = 'available',
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
