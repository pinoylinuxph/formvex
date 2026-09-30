<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormActivation\FormActivationEvidence;
use Formvex\Spoke\Domain\FormActivation\QualificationSession;
use Formvex\Spoke\Domain\FormActivation\QualificationStatus;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface FormActivationStore
{
    public function createCapability(
        PrivateStoragePaths $paths,
        string $capabilityId,
        string $capabilityTokenHash,
        string $sessionHash,
        FormConfigurationRecord $version,
        string $evidenceFingerprint,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void;

    /** @param list<string> $formMarkers */
    public function redeemCapability(
        PrivateStoragePaths $paths,
        string $capabilityTokenHash,
        PageIdentity $page,
        array $formMarkers,
        string $qualificationId,
        string $qualificationTokenHash,
        DateTimeImmutable $qualificationExpiresAt,
        DateTimeImmutable $now,
    ): QualificationSession;

    public function findSessionByToken(PrivateStoragePaths $paths, string $qualificationTokenHash, DateTimeImmutable $now): ?QualificationSession;

    public function recordAccepted(PrivateStoragePaths $paths, string $qualificationId, string $submissionId, DateTimeImmutable $now): void;

    public function qualificationStatus(PrivateStoragePaths $paths, string $qualificationId, DateTimeImmutable $now): ?QualificationStatus;

    public function evidence(PrivateStoragePaths $paths, int $versionId, int $versionNumber, DateTimeImmutable $now): FormActivationEvidence;

    public function recordEvidenceAccepted(PrivateStoragePaths $paths, int $versionId, string $fingerprint, string $qualificationId, DateTimeImmutable $now): void;

    public function recordEvidenceSent(PrivateStoragePaths $paths, int $versionId, string $qualificationId, DateTimeImmutable $now): void;

    public function recordEvidenceOutcome(PrivateStoragePaths $paths, int $versionId, string $qualificationId, QualificationStatus $status, string $failureCode, DateTimeImmutable $now): void;

    public function invalidateEvidence(PrivateStoragePaths $paths, int $versionId, string $reason, DateTimeImmutable $now): void;
}
