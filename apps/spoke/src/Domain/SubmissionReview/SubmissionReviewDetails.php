<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewDetails
{
    public function __construct(
        public string $publicId,
        public string $formPublicId,
        public string $formName,
        public int $configurationVersion,
        public string $pagePath,
        public string $formMarker,
        public string $acceptedAt,
        public string $classification,
        public string $lifecycle,
        public string $delivery,
        public bool $qualificationTest,
        public string $recipientMasked,
        public string $subject,
        public string $receiptId,
        /** @var list<SubmissionReviewField> */
        public array $fields,
        /** @var list<array{event: string, outcome: string, occurredAt: string}> */
        public array $auditEvents,
        public ?string $handledAt,
        public ?string $trashedAt,
    ) {
    }
}
