<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\StorageExport;

interface StorageExportRepository
{
    public function create(PrivateStoragePaths $paths, StorageExport $export, string $filterSummary): void;

    public function hasActive(PrivateStoragePaths $paths, DateTimeImmutable $now): bool;

    public function find(PrivateStoragePaths $paths, string $publicId): ?StorageExport;

    public function markDownloaded(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    public function expire(PrivateStoragePaths $paths, DateTimeImmutable $now): int;
}
