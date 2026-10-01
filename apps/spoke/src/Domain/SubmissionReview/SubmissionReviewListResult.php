<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

final readonly class SubmissionReviewListResult
{
    /** @param list<SubmissionReviewListItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public int $pageCount,
    ) {
    }
}
