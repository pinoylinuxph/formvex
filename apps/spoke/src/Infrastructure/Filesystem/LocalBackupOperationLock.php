<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Backup\Contract\BackupOperationLock;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

final class LocalBackupOperationLock implements BackupOperationLock
{
    public function acquire(PrivateStoragePaths $paths, string $operation): StorageLock
    {
        $lockPath = match ($operation) {
            'backup' => $paths->backupLockFile(),
            'restore' => $paths->restoreLockFile(),
            default => throw new InstallationFailure('backup_operation_invalid'),
        };
        $handle = fopen($lockPath, 'c+');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallationFailure('backup_operation_in_progress');
        }

        chmod($lockPath, 0o600);

        return new LocalStorageLock($handle, $lockPath);
    }
}
