<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings\Contract;

use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestResult;

interface SmtpTestTransport
{
    public function send(InstallationSettings $settings, string $recipient): SmtpTestResult;
}
