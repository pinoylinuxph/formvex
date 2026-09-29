<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryCandidate;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryPayload;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface FormDiscoveryStore
{
    public function createCapability(
        PrivateStoragePaths $paths,
        string $capabilityId,
        string $tokenHash,
        string $sessionHash,
        PageIdentity $page,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void;

    public function redeemAndCreateCandidate(
        PrivateStoragePaths $paths,
        string $tokenHash,
        PageIdentity $page,
        string $candidateId,
        DiscoveryPayload $payload,
        DateTimeImmutable $now,
    ): DiscoveryCandidate;

    /** @return list<DiscoveryCandidate> */
    public function listCandidates(PrivateStoragePaths $paths, string $sessionHash, DateTimeImmutable $now): array;

    public function findCandidate(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): ?DiscoveryCandidate;

    public function markCandidateApplied(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): void;

    public function markCandidateDiscarded(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): void;
}
