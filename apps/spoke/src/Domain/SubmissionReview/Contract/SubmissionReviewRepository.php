<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewActionResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewDetails;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewListResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;

interface SubmissionReviewRepository
{
    public function list(PrivateStoragePaths $paths, SubmissionReviewQuery $query): SubmissionReviewListResult;

    public function find(PrivateStoragePaths $paths, string $publicId): ?SubmissionReviewDetails;

    public function apply(PrivateStoragePaths $paths, string $publicId, SubmissionReviewAction $action, DateTimeImmutable $now): SubmissionReviewActionResult;
}
