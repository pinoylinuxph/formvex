<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Abuse\CaptchaOutageState;
use Formvex\Spoke\Domain\Abuse\CaptchaOutageTransition;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface CaptchaOutageStore
{
    public function getOutageState(PrivateStoragePaths $paths): CaptchaOutageState;

    public function recordFailure(PrivateStoragePaths $paths, string $failureCode, DateTimeImmutable $now): CaptchaOutageTransition;

    public function recordSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now): CaptchaOutageTransition;
}
