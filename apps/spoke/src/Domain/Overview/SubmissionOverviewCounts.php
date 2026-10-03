<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Overview;

final readonly class SubmissionOverviewCounts
{
    public function __construct(
        public int $total,
        public int $unhandled,
        public int $suspectedSpam,
    ) {
    }
}
