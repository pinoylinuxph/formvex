<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class InstallationMarker
{
    public function __construct(
        public string $installationId,
        public string $schemaVersion,
    ) {
    }
}
