<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface BackupRepository
{
    /** @return list<BackupArchive> */
    public function list(PrivateStoragePaths $paths): array;

    public function find(PrivateStoragePaths $paths, string $publicId): ?BackupArchive;

    public function create(PrivateStoragePaths $paths, string $publicId, BackupKind $kind, string $storageKey, string $schemaVersion, DateTimeImmutable $now): void;

    public function complete(PrivateStoragePaths $paths, string $publicId, int $sizeBytes, string $sha256, DateTimeImmutable $now): void;

    public function fail(PrivateStoragePaths $paths, string $publicId, string $failureCode): void;

    public function beginDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): BackupArchive;

    public function finishDownload(PrivateStoragePaths $paths, string $publicId): void;

    public function delete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;
}
