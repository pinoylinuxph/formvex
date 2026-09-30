<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

use DateInterval;
use DateTimeImmutable;

final class DeliveryRetryPolicy
{
    /** @var list<int> */
    private const RETRY_DELAYS = [60, 300, 900, 3600, 21600];

    public function nextDueAt(int $attemptNumber, DeliveryOutcomeType $outcome, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($outcome !== DeliveryOutcomeType::TEMPORARY || $attemptNumber < 1 || $attemptNumber > 5) {
            return null;
        }

        return $now->add(new DateInterval('PT' . self::RETRY_DELAYS[$attemptNumber - 1] . 'S'));
    }
}
