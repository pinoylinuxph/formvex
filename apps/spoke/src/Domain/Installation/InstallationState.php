<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

enum InstallationState: string
{
    case Uninitialized = 'uninitialized';
    case Initializing = 'initializing';
    case Initialized = 'initialized';
    case Incomplete = 'incomplete';
}
