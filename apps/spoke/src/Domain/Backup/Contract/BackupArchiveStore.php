<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Backup\BackupArtifact;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface BackupArchiveStore
{
    public function currentSchemaVersion(PrivateStoragePaths $paths): string;

    public function create(PrivateStoragePaths $paths, string $publicId, BackupKind $kind, DateTimeImmutable $createdAt): BackupArtifact;

    public function verify(PrivateStoragePaths $paths, string $archivePath): string;

    public function archivePath(PrivateStoragePaths $paths, string $storageKey): string;

    public function delete(PrivateStoragePaths $paths, string $storageKey): void;

    public function restore(PrivateStoragePaths $paths, string $archivePath): void;
}
