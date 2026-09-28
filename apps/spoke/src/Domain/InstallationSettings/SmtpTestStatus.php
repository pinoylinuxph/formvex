<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

enum SmtpTestStatus: string
{
    case NOT_CONFIGURED = 'not_configured';
    case PASSED = 'passed';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
    case STALE = 'stale';
}
