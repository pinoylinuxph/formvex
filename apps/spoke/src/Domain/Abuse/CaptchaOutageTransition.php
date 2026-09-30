<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

final readonly class CaptchaOutageTransition
{
    public function __construct(
        public CaptchaOutageState $state,
        public ?string $event,
    ) {
    }
}
