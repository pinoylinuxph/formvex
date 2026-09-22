<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

interface WebExposureVerifier
{
    public function isDenied(string $url): bool;
}
