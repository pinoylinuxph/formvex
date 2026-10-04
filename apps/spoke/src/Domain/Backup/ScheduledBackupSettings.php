<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Backup;

use DateTimeImmutable;

final readonly class ScheduledBackupSettings
{
    public function __construct(
        public bool $enabled,
        public ScheduledBackupFrequency $frequency,
        public int $weekday,
        public int $hour,
        public int $minute,
        public int $retentionCount,
        public ?string $lastDuePeriod,
        public ?DateTimeImmutable $lastAttemptAt,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?string $lastStatus,
        public ?string $lastErrorCode,
        public ?string $lastCandidateId,
        public ?DateTimeImmutable $lastCleanupAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function defaults(DateTimeImmutable $now): self
    {
        return new self(false, ScheduledBackupFrequency::MONTHLY, 0, 0, 0, 4, null, null, null, null, null, null, null, $now);
    }

    public function scheduleLabel(): string
    {
        return sprintf('%s at %02d:%02d UTC', $this->frequency->label(), $this->hour, $this->minute);
    }
}
