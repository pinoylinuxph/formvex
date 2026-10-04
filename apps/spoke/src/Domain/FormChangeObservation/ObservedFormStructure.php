<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormChangeObservation;

final readonly class ObservedFormStructure
{
    /** @param list<array{control_name: string, control_type: string, required: bool, max_length: int, choice_values: list<string>}> $controls */
    public function __construct(
        public int $schemaVersion,
        public int $configurationVersion,
        public string $pagePath,
        public string $formMarker,
        public string $sourceFingerprint,
        public array $controls,
    ) {
    }
}
