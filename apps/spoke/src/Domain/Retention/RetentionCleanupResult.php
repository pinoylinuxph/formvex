<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Retention;

final readonly class RetentionCleanupResult
{
    public function __construct(
        public string $status,
        public int $scanned,
        public int $deleted,
        public int $deferred,
        public int $failed,
        public ?string $errorCode = null,
        public bool $succeeded = true,
    ) {
    }

    public static function locked(): self
    {
        return new self('locked', 0, 0, 0, 0);
    }

    public function safeSummary(): string
    {
        $prefix = $this->succeeded ? 'OK' : 'FAIL';

        return sprintf(
            '%s retention_cleanup: status=%s scanned=%d deleted=%d deferred=%d failed=%d%s.',
            $prefix,
            $this->status,
            $this->scanned,
            $this->deleted,
            $this->deferred,
            $this->failed,
            $this->errorCode === null ? '' : ' error=' . $this->errorCode,
        );
    }
}
