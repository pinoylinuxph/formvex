<?php

declare(strict_types=1);

namespace Formvex\Contracts\Spoke\FormDiscovery;

final readonly class FormDiscoveryResponse
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $candidateId,
        public string $status,
        public string $expiresAt,
    ) {
    }

    /** @return array{schema_version: int, candidate_id: string, status: string, expires_at: string} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate_id' => $this->candidateId,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
        ];
    }
}
