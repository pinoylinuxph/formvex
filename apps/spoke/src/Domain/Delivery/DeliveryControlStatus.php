<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

use InvalidArgumentException;

final readonly class DeliveryControlStatus
{
    public function __construct(
        public string $state,
        public ?string $changedAt = null,
        public ?string $changedBy = null,
    ) {
        if (!in_array($state, ['running', 'paused'], true)) {
            throw new InvalidArgumentException('The delivery control state is invalid.');
        }
    }

    public function isPaused(): bool
    {
        return $this->state === 'paused';
    }

    public function label(): string
    {
        return $this->isPaused() ? 'Paused' : 'Running';
    }

    public function explanation(): string
    {
        return $this->isPaused()
            ? 'Contact-message delivery is paused. Valid visitor submissions can still be accepted while storage is available.'
            : 'Contact-message delivery is running. The worker will process eligible messages under the configured attempt limit.';
    }
}
