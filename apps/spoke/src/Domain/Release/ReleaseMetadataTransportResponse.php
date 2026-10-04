<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

final readonly class ReleaseMetadataTransportResponse
{
    public function __construct(
        public string $body,
        public int $status,
        public string $contentType,
        public ?string $failureCode = null,
        public bool $tooLarge = false,
    ) {
    }
}
