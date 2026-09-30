<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;

final readonly class QualificationSession
{
    public function __construct(
        public string $qualificationId,
        public string $publicFormId,
        public int $versionNumber,
        public PageIdentity $page,
        public string $evidenceFingerprint,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $submittedAt = null,
        public ?string $submissionId = null,
    ) {
    }
}
