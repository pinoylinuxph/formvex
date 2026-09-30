<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation;

use DateTimeImmutable;

final readonly class QualificationAuthorization
{
    public function __construct(
        public string $capabilityId,
        public string $token,
        public string $qualificationUrl,
        public DateTimeImmutable $expiresAt,
        public string $publicFormId,
        public int $versionNumber,
        public string $formMarker,
    ) {
    }
}
