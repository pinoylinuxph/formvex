<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Captcha;

final readonly class TurnstileHttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $error = null,
    ) {
    }
}
