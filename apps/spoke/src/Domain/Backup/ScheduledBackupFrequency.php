<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

enum ScheduledBackupFrequency: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::DAILY => 'Daily',
            self::WEEKLY => 'Weekly',
            self::MONTHLY => 'Monthly',
        };
    }
}
