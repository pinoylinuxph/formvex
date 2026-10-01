<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryReviewListResult
{
    /** @param list<DeliveryReviewListItem> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $pageSize,
        public int $pageCount,
    ) {
    }
}
