<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormChangeObservation\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservation;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface FormChangeObservationRepository
{
    /** @param list<array{kind: string, control_key: string, control_type: string}> $differences */
    public function record(
        PrivateStoragePaths $paths,
        string $publicId,
        string $formPublicId,
        int $configurationVersion,
        string $pagePath,
        string $formMarker,
        string $fingerprint,
        array $differences,
        DateTimeImmutable $observedAt,
    ): void;

    /** @return list<FormChangeObservation> */
    public function listOpen(PrivateStoragePaths $paths): array;

    public function countOpen(PrivateStoragePaths $paths): int;

    public function resolve(PrivateStoragePaths $paths, string $publicId, string $actor, DateTimeImmutable $resolvedAt): void;

    public function discard(PrivateStoragePaths $paths, string $publicId, string $actor, DateTimeImmutable $discardedAt): void;
}
