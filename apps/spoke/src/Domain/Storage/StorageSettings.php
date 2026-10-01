<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

use InvalidArgumentException;

final readonly class StorageSettings
{
    public const BYTES_PER_GIGABYTE = 1000000000;

    public const DEFAULT_ALLOWANCE_BYTES = 2000000000;

    public const DEFAULT_NORMAL_WARNING_PERCENT = 80;

    public const DEFAULT_CRITICAL_WARNING_PERCENT = 90;

    public function __construct(
        public int $allowanceBytes = self::DEFAULT_ALLOWANCE_BYTES,
        public int $normalWarningPercent = self::DEFAULT_NORMAL_WARNING_PERCENT,
        public int $criticalWarningPercent = self::DEFAULT_CRITICAL_WARNING_PERCENT,
    ) {
        if ($this->allowanceBytes < self::BYTES_PER_GIGABYTE || $this->allowanceBytes > 10 * self::BYTES_PER_GIGABYTE) {
            throw new InvalidArgumentException('The storage allowance must be between 1 GB and 10 GB.');
        }
        if ($this->normalWarningPercent < 1 || $this->normalWarningPercent > 99) {
            throw new InvalidArgumentException('The normal storage warning must be between 1% and 99%.');
        }
        if ($this->criticalWarningPercent < 2 || $this->criticalWarningPercent > 99) {
            throw new InvalidArgumentException('The critical storage warning must be between 2% and 99%.');
        }
        if ($this->normalWarningPercent >= $this->criticalWarningPercent) {
            throw new InvalidArgumentException('The normal storage warning must be below the critical storage warning.');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function allowanceGigabytes(): int
    {
        return intdiv($this->allowanceBytes, self::BYTES_PER_GIGABYTE);
    }
}
