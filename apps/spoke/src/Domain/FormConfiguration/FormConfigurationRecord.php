<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use DateTimeImmutable;

final readonly class FormConfigurationRecord
{
    /** @param list<FormFieldDefinition> $fields */
    public function __construct(
        public string $publicId,
        public string $displayName,
        public int $versionNumber,
        public int $revision,
        public FormConfigurationState $state,
        public PageIdentity $page,
        public string $recipient,
        public string $subject,
        public array $fields,
        public int $evidenceRevision,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $publishedAt,
        public bool $captchaEnabled = false,
        public string $captchaSiteKey = '',
        public int $versionId = 0,
    ) {
    }
}
