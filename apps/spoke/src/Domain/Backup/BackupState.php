<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

enum BackupState: string
{
    case CREATING = 'creating';
    case VERIFIED = 'verified';
    case FAILED = 'failed';
}
