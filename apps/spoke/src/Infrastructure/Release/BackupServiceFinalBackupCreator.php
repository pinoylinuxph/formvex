<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\FinalBackupCreator;
use RuntimeException;

final readonly class BackupServiceFinalBackupCreator implements FinalBackupCreator
{
    public function __construct(private BackupService $backupService, private BackupArchiveStore $archiveStore)
    {
    }

    public function create(string $applicationRoot, PrivateStoragePaths $paths): BackupArchive
    {
        $backup = $this->backupService->create($applicationRoot, BackupKind::PRE_UPGRADE);
        $path = $this->archiveStore->archivePath($paths, $backup->storageKey);
        if ($this->archiveStore->verify($paths, $path) !== $backup->schemaVersion) {
            throw new RuntimeException('The final pre-upgrade backup schema does not match its inventory.');
        }

        return $backup;
    }
}
