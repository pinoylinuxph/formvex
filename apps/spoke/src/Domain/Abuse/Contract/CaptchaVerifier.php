<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse\Contract;

use Formvex\Spoke\Domain\Abuse\CaptchaVerificationResult;

interface CaptchaVerifier
{
    public function verify(string $secret, string $token, string $remoteIp, string $expectedHostname, string $expectedAction): CaptchaVerificationResult;
}
