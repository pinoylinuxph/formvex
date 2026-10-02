<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

use DateTimeImmutable;

final readonly class UpgradeMaintenanceState
{
    public function __construct(
        public string $operationId,
        public string $currentRelease,
        public string $targetRelease,
        public string $currentSchema,
        public string $targetSchema,
        public string $state,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $drainDeadlineAt,
    ) {
    }

    public function withState(string $state): self
    {
        return new self($this->operationId, $this->currentRelease, $this->targetRelease, $this->currentSchema, $this->targetSchema, $state, $this->startedAt, $this->drainDeadlineAt);
    }
}
