<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Delivery\DeliveryResendResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewDetails;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewListResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewQuery;
use Formvex\Spoke\Domain\Delivery\DeliveryWarning;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DeliveryReviewRepository
{
    public function list(PrivateStoragePaths $paths, DeliveryReviewQuery $query): DeliveryReviewListResult;

    public function find(PrivateStoragePaths $paths, string $publicId): ?DeliveryReviewDetails;

    public function resend(PrivateStoragePaths $paths, string $publicId, bool $confirmUncertain, DateTimeImmutable $now): DeliveryResendResult;

    /** @return list<DeliveryWarning> */
    public function warnings(PrivateStoragePaths $paths, DateTimeImmutable $now): array;
}
