<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\Filesystem;

use Formvex\Spoke\Infrastructure\Filesystem\WebExposureVerifier;

final class RecordingWebExposureVerifier implements WebExposureVerifier
{
    /** @var list<string> */
    public array $urls = [];

    public function __construct(private readonly bool $denied)
    {
    }

    public function isDenied(string $url): bool
    {
        $this->urls[] = $url;

        return $this->denied;
    }
}
