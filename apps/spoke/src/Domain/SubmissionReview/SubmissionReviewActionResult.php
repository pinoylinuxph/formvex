<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewActionResult
{
    public function __construct(
        public bool $changed,
        public string $variant,
        public string $message,
    ) {
    }
}
