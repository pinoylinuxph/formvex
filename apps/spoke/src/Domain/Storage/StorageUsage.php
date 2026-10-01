<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

final readonly class StorageUsage
{
    public function __construct(
        public int $liveBytes,
        public int $allowanceBytes,
        public int $percent,
        public StorageState $state,
        public ?int $physicalFreeBytes,
        /** @var array<string, int> */
        public array $breakdown = [],
    ) {
    }

    public static function unavailable(StorageSettings $settings): self
    {
        return new self(0, $settings->allowanceBytes, 0, StorageState::UNAVAILABLE, null, []);
    }

    public function isAvailableForWrite(): bool
    {
        return $this->state !== StorageState::UNAVAILABLE && $this->state !== StorageState::FULL;
    }
}
