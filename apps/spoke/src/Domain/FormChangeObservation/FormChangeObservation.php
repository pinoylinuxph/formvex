<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormChangeObservation;

use DateTimeImmutable;

final readonly class FormChangeObservation
{
    /** @param list<array{kind: string, control_key: string, control_type: string}> $differences */
    public function __construct(
        public string $publicId,
        public string $formPublicId,
        public int $configurationVersion,
        public string $pagePath,
        public string $formMarker,
        public array $differences,
        public DateTimeImmutable $firstObservedAt,
        public DateTimeImmutable $lastObservedAt,
    ) {
    }
}
