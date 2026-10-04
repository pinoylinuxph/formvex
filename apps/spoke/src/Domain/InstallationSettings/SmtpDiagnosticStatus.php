<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

enum SmtpDiagnosticStatus: string
{
    case NOT_CONFIGURED = 'not_configured';
    case PASSED_CURRENT = 'passed_current';
    case PASSED_STALE = 'passed_stale';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
    case UNAVAILABLE = 'unavailable';
}
