<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Backup\ScheduledBackupArchive;
use Formvex\Spoke\Domain\Backup\ScheduledBackupSettings;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface ScheduledBackupRepository
{
    public function settings(PrivateStoragePaths $paths): ScheduledBackupSettings;

    public function saveSettings(PrivateStoragePaths $paths, ScheduledBackupSettings $settings, DateTimeImmutable $now): void;

    public function claimDue(PrivateStoragePaths $paths, string $duePeriod, DateTimeImmutable $now): bool;

    /** @return list<ScheduledBackupArchive> */
    public function list(PrivateStoragePaths $paths): array;

    public function find(PrivateStoragePaths $paths, string $publicId): ?ScheduledBackupArchive;

    public function create(PrivateStoragePaths $paths, string $publicId, string $duePeriod, string $storageKey, string $schemaVersion, DateTimeImmutable $now): void;

    public function complete(PrivateStoragePaths $paths, string $publicId, int $sizeBytes, string $sha256, DateTimeImmutable $now): void;

    public function fail(PrivateStoragePaths $paths, string $publicId, string $failureCode, DateTimeImmutable $now): void;

    public function beginDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): ScheduledBackupArchive;

    public function finishDownload(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    /** @return list<ScheduledBackupArchive> */
    public function abandonedCandidates(PrivateStoragePaths $paths, DateTimeImmutable $cutoff): array;

    public function recoverStaleDownloads(PrivateStoragePaths $paths, DateTimeImmutable $cutoff, DateTimeImmutable $now): int;

    /** @return list<ScheduledBackupArchive> */
    public function rotationCandidates(PrivateStoragePaths $paths): array;

    public function delete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    public function markRun(PrivateStoragePaths $paths, string $status, ?string $errorCode, ?string $candidateId, DateTimeImmutable $now): void;
}
