<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewListItem
{
    public function __construct(
        public string $publicId,
        public string $formPublicId,
        public string $formName,
        public string $acceptedAt,
        public string $classification,
        public string $lifecycle,
        public string $delivery,
        public bool $qualificationTest,
        public string $recipientMasked,
    ) {
    }
}
