<?php

declare(strict_types=1);

namespace Formvex\Core\Release;

final readonly class ReleaseVerificationResult
{
    /**
     * @param list<string> $entries
     */
    public function __construct(
        public string $releaseVersion,
        public string $schemaMinimum,
        public string $schemaMaximum,
        public array $entries,
    ) {
    }
}
