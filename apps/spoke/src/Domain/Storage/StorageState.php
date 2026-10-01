<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

enum StorageState: string
{
    case NORMAL = 'normal';
    case WARNING = 'warning';
    case CRITICAL = 'critical';
    case FULL = 'full';
    case UNAVAILABLE = 'unavailable';
}
