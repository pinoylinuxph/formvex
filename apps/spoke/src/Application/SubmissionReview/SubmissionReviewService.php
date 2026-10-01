<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\SubmissionReview;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\SubmissionReview\Contract\SubmissionReviewRepository;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewActionResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewDetails;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewListResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;

final readonly class SubmissionReviewService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private SubmissionReviewRepository $repository,
        private Clock $clock,
    ) {
    }

    public function list(string $applicationRoot, SubmissionReviewQuery $query): SubmissionReviewListResult
    {
        return $this->repository->list($this->storageResolver->resolve($applicationRoot), $query);
    }

    public function details(string $applicationRoot, string $publicId): SubmissionReviewDetails
    {
        $this->assertPublicId($publicId);
        $details = $this->repository->find($this->storageResolver->resolve($applicationRoot), $publicId);

        if ($details === null) {
            throw new SubmissionReviewFailure('not_found', 'The requested submission could not be found. It may have been removed or is no longer available.');
        }

        return $details;
    }

    public function apply(string $applicationRoot, string $publicId, SubmissionReviewAction $action): SubmissionReviewActionResult
    {
        $this->assertPublicId($publicId);

        return $this->repository->apply(
            $this->storageResolver->resolve($applicationRoot),
            $publicId,
            $action,
            $this->clock->now(),
        );
    }

    private function assertPublicId(string $publicId): void
    {
        if (!preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $publicId)) {
            throw new SubmissionReviewFailure('not_found', 'The requested submission could not be found.');
        }
    }
}
