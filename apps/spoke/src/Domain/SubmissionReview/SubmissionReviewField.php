<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewField
{
    public function __construct(
        public string $label,
        public string $value,
        public bool $missing,
        public string $preview,
        public bool $long,
    ) {
    }
}
