<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Submission\Contract;

use DateTimeImmutable;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Submission\SubmissionAccepted;
use Formvex\Spoke\Domain\Submission\SubmissionClassification;

interface SubmissionStore
{
    public function findAcceptedAttempt(
        PrivateStoragePaths $paths,
        string $publicFormId,
        string $attemptId,
        string $payloadHash,
        DateTimeImmutable $now,
    ): ?SubmissionAccepted;

    /** @param array<string, string|list<string>> $validatedFields */
    public function accept(
        PrivateStoragePaths $paths,
        FormConfigurationRecord $configuration,
        SubmissionRequest $request,
        array $validatedFields,
        string $payloadHash,
        SubmissionClassification $classification,
        DateTimeImmutable $now,
        string $submissionId,
        string $receiptId,
        string $jobId,
    ): SubmissionAccepted;
}
