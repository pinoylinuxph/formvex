<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use DateTimeImmutable;

final readonly class FormConfigurationSummary
{
    public function __construct(
        public string $publicId,
        public string $displayName,
        public int $draftRevision,
        public int $publishedVersion,
        public ?FormConfigurationState $publishedState,
        public bool $trashed,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
