<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Captcha;

interface TurnstileHttpClient
{
    public function post(string $endpoint, string $body): TurnstileHttpResponse;
}
