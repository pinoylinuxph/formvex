<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryReviewDetails
{
    /**
     * @param list<DeliveryReviewAttempt> $attempts
     * @param list<array{event: string, outcome: string, occurredAt: string}> $auditEvents
     */
    public function __construct(
        public string $publicId,
        public string $submissionPublicId,
        public string $formName,
        public int $configurationVersion,
        public string $state,
        public string $outcome,
        public string $lastErrorCode,
        public string $lastErrorMessage,
        public string $recipientMasked,
        public string $acceptedAt,
        public string $lastActivity,
        public ?string $nextDueAt,
        public int $attemptCount,
        public int $manualCycleCount,
        public bool $resendAllowed,
        public bool $uncertainConfirmationRequired,
        public string $resendUnavailableReason,
        public array $attempts,
        public array $auditEvents,
    ) {
    }
}
