<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Retention\Contract\RetentionLock;

final class LocalRetentionLock implements RetentionLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        $lockFile = $paths->retentionLockFile();
        $handle = fopen($lockFile, 'c+');

        if ($handle === false) {
            throw new InstallationFailure('retention_lock_unavailable');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallationFailure('installation_in_progress');
        }

        chmod($lockFile, 0o600);

        return new LocalStorageLock($handle, $lockFile);
    }
}
