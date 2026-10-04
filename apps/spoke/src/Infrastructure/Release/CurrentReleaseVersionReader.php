<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use JsonException;

final readonly class CurrentReleaseVersionReader
{
    public function __construct(private string $projectRoot)
    {
    }

    public function read(): string
    {
        $manifestPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'RELEASE-MANIFEST.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            return 'unversioned';
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'unversioned';
        }

        $version = is_array($manifest) ? ($manifest['release_version'] ?? null) : null;

        return is_string($version) && preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $version) === 1
            ? $version
            : 'unversioned';
    }
}
