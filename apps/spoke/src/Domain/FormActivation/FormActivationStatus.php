<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;

final readonly class FormActivationStatus
{
    public function __construct(
        public ?FormConfigurationRecord $targetVersion,
        public ?FormConfigurationRecord $activeVersion,
        public FormActivationEvidence $evidence,
        public bool $smtpTestCurrent,
        public bool $endToEndCurrent,
        public bool $activationAllowed,
        public ?QualificationStatus $qualificationStatus,
        public ?string $qualificationFailureCode,
        public ?DateTimeImmutable $qualificationExpiresAt,
    ) {
    }

    public function hasTarget(): bool
    {
        return $this->targetVersion !== null;
    }
}
