<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

final readonly class FormConfigurationDetails
{
    /** @param list<FormConfigurationRecord> $publishedVersions */
    public function __construct(
        public FormConfigurationRecord $draft,
        public array $publishedVersions,
        public bool $trashed,
    ) {
    }
}
