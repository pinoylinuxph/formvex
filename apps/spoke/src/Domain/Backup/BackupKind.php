<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

enum BackupKind: string
{
    case MANUAL = 'manual';
    case PRE_UPGRADE = 'pre_upgrade';
    case SCHEDULED = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::PRE_UPGRADE => 'Pre-upgrade',
            self::SCHEDULED => 'Scheduled',
        };
    }
}
