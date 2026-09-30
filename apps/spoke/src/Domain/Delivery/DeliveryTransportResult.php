<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

use Formvex\Core\Delivery\DeliveryOutcomeType;

final readonly class DeliveryTransportResult
{
    public function __construct(
        public DeliveryOutcomeType $outcome,
        public string $errorCode = '',
    ) {
    }

    public static function accepted(): self
    {
        return new self(DeliveryOutcomeType::ACCEPTED);
    }

    public static function temporary(string $errorCode): self
    {
        return new self(DeliveryOutcomeType::TEMPORARY, $errorCode);
    }

    public static function permanent(string $errorCode): self
    {
        return new self(DeliveryOutcomeType::PERMANENT, $errorCode);
    }

    public static function uncertain(): self
    {
        return new self(DeliveryOutcomeType::UNCERTAIN, 'smtp_result_uncertain');
    }
}
