<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

use DateTimeImmutable;

final readonly class DiscoveryCandidate
{
    /** @param list<DiscoveryForm> $forms */
    public function __construct(
        public string $candidateId,
        public string $host,
        public string $path,
        public int $schemaVersion,
        public DiscoveryCandidateStatus $status,
        public array $forms,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'page_path' => $this->path,
            'forms' => array_map(static fn (DiscoveryForm $form): array => $form->toArray(), $this->forms),
        ];
    }
}
