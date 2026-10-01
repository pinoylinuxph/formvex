<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryReviewListItem
{
    public function __construct(
        public string $publicId,
        public string $submissionPublicId,
        public string $formName,
        public string $state,
        public string $outcome,
        public string $attempts,
        public string $lastActivity,
        public ?string $nextDueAt,
        public string $recipientMasked,
        public int $configurationVersion,
    ) {
    }
}
