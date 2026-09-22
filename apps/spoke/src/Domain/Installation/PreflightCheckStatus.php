<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

enum PreflightCheckStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Fail = 'fail';
}
