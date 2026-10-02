<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\UpgradeOperationLock;

final class LocalUpgradeOperationLock implements UpgradeOperationLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        $handle = fopen($paths->upgradeLockFile(), 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallationFailure('upgrade_in_progress');
        }

        chmod($paths->upgradeLockFile(), 0o600);

        return new LocalStorageLock($handle, $paths->upgradeLockFile());
    }
}
