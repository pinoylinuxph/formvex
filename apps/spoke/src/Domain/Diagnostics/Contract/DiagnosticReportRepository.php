<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Diagnostics\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportMetadata;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DiagnosticReportRepository
{
    public function latest(PrivateStoragePaths $paths): ?DiagnosticReportMetadata;

    public function find(PrivateStoragePaths $paths, string $publicId): ?DiagnosticReportMetadata;

    /** @return list<DiagnosticReportMetadata> */
    public function available(PrivateStoragePaths $paths): array;

    /** @return list<DiagnosticReportMetadata> */
    public function expired(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limit): array;

    public function publish(PrivateStoragePaths $paths, DiagnosticReportMetadata $metadata, DateTimeImmutable $now): void;

    public function remove(PrivateStoragePaths $paths, string $publicId, string $actor, string $outcome, DateTimeImmutable $now): void;

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, string $actor, ?string $publicId, DateTimeImmutable $now): void;
}
