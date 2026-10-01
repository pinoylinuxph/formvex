<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Backup;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Backup\BackupArtifact;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Backup\Contract\BackupOperationLock;
use Formvex\Spoke\Domain\Backup\Contract\BackupRepository;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Throwable;

final readonly class BackupService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private BackupRepository $repository,
        private BackupArchiveStore $archiveStore,
        private BackupOperationLock $operationLock,
        private RecoveryHoldStore $recoveryHoldStore,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function create(string $applicationRoot, BackupKind $kind): BackupArchive
    {
        $paths = $this->paths($applicationRoot);
        $this->assertAvailable($paths);
        $lock = $this->operationLock->acquire($paths, 'backup');
        $publicId = $this->identifierGenerator->uuidV7($this->clock->now());
        $now = $this->clock->now();
        $storageKey = ($kind === BackupKind::PRE_UPGRADE ? 'pre_upgrade/' : 'manual/') . $publicId . '.zip';
        $created = false;
        $artifact = null;
        try {
            $schemaVersion = $this->archiveStore->currentSchemaVersion($paths);
            $this->repository->create($paths, $publicId, $kind, $storageKey, $schemaVersion, $now);
            $created = true;
            $artifact = $this->archiveStore->create($paths, $publicId, $kind, $now);
            $this->repository->complete($paths, $publicId, $artifact->sizeBytes, $artifact->sha256, $this->clock->now());
            $archive = $this->repository->find($paths, $publicId);
            if ($archive === null) {
                throw new BackupFailure('backup_inventory_missing', 'The backup was created but its inventory record could not be read.');
            }

            return $archive;
        } catch (BackupFailure $failure) {
            if ($artifact instanceof BackupArtifact) {
                try {
                    $this->archiveStore->delete($paths, $artifact->storageKey);
                } catch (Throwable) {
                    // The failed inventory state remains authoritative for server-side cleanup.
                }
            }
            if ($created) {
                $this->repository->fail($paths, $publicId, $failure->failureCode);
            }
            throw $failure;
        } catch (Throwable $failure) {
            if ($artifact instanceof BackupArtifact) {
                try {
                    $this->archiveStore->delete($paths, $artifact->storageKey);
                } catch (Throwable) {
                    // The failed inventory state remains authoritative for server-side cleanup.
                }
            }
            if ($created) {
                $this->repository->fail($paths, $publicId, 'backup_failed');
            }
            throw new BackupFailure('backup_failed', 'The backup could not be completed safely. Existing completed backups were not changed.', $failure);
        } finally {
            $lock->release();
        }
    }

    /** @return list<BackupArchive> */
    public function list(string $applicationRoot): array
    {
        return $this->repository->list($this->paths($applicationRoot));
    }

    /** @return array{archive: BackupArchive, path: string} */
    public function beginDownload(string $applicationRoot, string $publicId): array
    {
        $paths = $this->paths($applicationRoot);
        $this->assertAvailable($paths);
        if (!preg_match('/\A[0-9a-f-]{16,80}\z/i', $publicId)) {
            throw new BackupFailure('backup_not_available', 'The selected backup is not available. Refresh Maintenance and try again.');
        }
        $archive = $this->repository->find($paths, $publicId);
        if ($archive === null || !$archive->isDownloadable()) {
            throw new BackupFailure('backup_not_available', 'The selected backup is not available. Refresh Maintenance and try again.');
        }
        $path = $this->archiveStore->archivePath($paths, $archive->storageKey);
        if (!is_file($path) || is_link($path)) {
            throw new BackupFailure('backup_not_available', 'The selected backup is not available. Refresh Maintenance and try again.');
        }
        $started = $this->repository->beginDownload($paths, $publicId, $this->clock->now());

        return ['archive' => $started, 'path' => $path];
    }

    public function finishDownload(string $applicationRoot, string $publicId): void
    {
        $this->repository->finishDownload($this->paths($applicationRoot), $publicId);
    }

    public function delete(string $applicationRoot, string $publicId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->assertAvailable($paths);
        $archive = $this->repository->find($paths, $publicId);
        if ($archive === null || !$archive->isDownloadable()) {
            throw new BackupFailure('backup_not_available', 'The selected backup is not available for deletion. Refresh Maintenance and try again.');
        }
        if ($archive->activeDownloads > 0) {
            throw new BackupFailure('backup_download_active', 'The selected backup is being downloaded. Wait for that download to finish before deleting it.');
        }
        $this->archiveStore->delete($paths, $archive->storageKey);
        $this->repository->delete($paths, $publicId, $this->clock->now());
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function assertAvailable(PrivateStoragePaths $paths): void
    {
        if ($this->recoveryHoldStore->current($paths) !== null) {
            throw new BackupFailure('recovery_hold_active', 'Backup actions are unavailable while the installation is in recovery hold. The hosting administrator must complete or repair the restore.');
        }
    }

}
