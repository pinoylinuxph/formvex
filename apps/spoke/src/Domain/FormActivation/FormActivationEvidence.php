<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation;

use DateTimeImmutable;

final readonly class FormActivationEvidence
{
    public function __construct(
        public int $versionNumber,
        public int $evidenceRevision,
        public ?int $smtpTestRevision,
        public QualificationStatus $endToEndStatus,
        public ?string $endToEndFailureCode,
        public ?string $endToEndFingerprint,
        public ?DateTimeImmutable $endToEndAcceptedAt,
        public ?DateTimeImmutable $endToEndDeliveryAcceptedAt,
        public ?string $qualificationId,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function empty(int $versionNumber, DateTimeImmutable $updatedAt): self
    {
        return new self($versionNumber, 1, null, QualificationStatus::NOT_RUN, null, null, null, null, null, $updatedAt);
    }

    public function isCurrentFor(string $fingerprint): bool
    {
        return $this->endToEndFingerprint !== null && hash_equals($this->endToEndFingerprint, $fingerprint);
    }

    public function endToEndPassed(string $fingerprint): bool
    {
        return $this->endToEndStatus === QualificationStatus::SENT && $this->isCurrentFor($fingerprint);
    }
}
