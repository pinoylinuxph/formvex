<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

final readonly class DeliveryWorkerResult
{
    public function __construct(
        public int $claimed,
        public int $sent,
        public int $retried,
        public int $failed,
        public int $uncertain,
        public int $deferred,
        public bool $succeeded = true,
    ) {
    }

    public function safeSummary(): string
    {
        return sprintf(
            '%s delivery_worker: claimed=%d sent=%d retried=%d failed=%d uncertain=%d deferred=%d.',
            $this->succeeded ? 'OK' : 'FAIL',
            $this->claimed,
            $this->sent,
            $this->retried,
            $this->failed,
            $this->uncertain,
            $this->deferred,
        );
    }
}
