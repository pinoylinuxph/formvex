<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckLock;

final class LocalReleaseCheckLock implements ReleaseCheckLock
{
    public function acquire(PrivateStoragePaths $paths): StorageLock
    {
        $path = $paths->releaseCheckLockFile();
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallationFailure('release_check_in_progress');
        }

        chmod($path, 0o600);

        return new LocalStorageLock($handle, $path);
    }
}
