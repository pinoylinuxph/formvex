<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Backup\BackupArchive;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface FinalBackupCreator
{
    public function create(string $applicationRoot, PrivateStoragePaths $paths): BackupArchive;
}
