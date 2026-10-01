<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\DeliveryReview;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryReviewRepository;
use Formvex\Spoke\Domain\Delivery\DeliveryResendResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewDetails;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewFailure;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewListResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewQuery;
use Formvex\Spoke\Domain\Delivery\DeliveryWarning;
use Formvex\Spoke\Domain\Installation\Contract\Clock;

final readonly class DeliveryReviewService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private DeliveryReviewRepository $repository,
        private Clock $clock,
    ) {
    }

    public function list(string $applicationRoot, DeliveryReviewQuery $query): DeliveryReviewListResult
    {
        return $this->repository->list($this->storageResolver->resolve($applicationRoot), $query);
    }

    public function details(string $applicationRoot, string $publicId): DeliveryReviewDetails
    {
        $this->assertPublicId($publicId);
        $details = $this->repository->find($this->storageResolver->resolve($applicationRoot), $publicId);
        if ($details === null) {
            throw new DeliveryReviewFailure('not_found', 'The requested delivery record could not be found. It may have been removed or is no longer available.');
        }

        return $details;
    }

    /** @return list<DeliveryWarning> */
    public function warnings(string $applicationRoot): array
    {
        return $this->repository->warnings($this->storageResolver->resolve($applicationRoot), $this->clock->now());
    }

    public function resend(string $applicationRoot, string $publicId, bool $confirmUncertain): DeliveryResendResult
    {
        $this->assertPublicId($publicId);

        return $this->repository->resend(
            $this->storageResolver->resolve($applicationRoot),
            $publicId,
            $confirmUncertain,
            $this->clock->now(),
        );
    }

    private function assertPublicId(string $publicId): void
    {
        if (!preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $publicId)) {
            throw new DeliveryReviewFailure('not_found', 'The requested delivery record could not be found.');
        }
    }
}
