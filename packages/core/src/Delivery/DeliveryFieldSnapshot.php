<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

use InvalidArgumentException;

final readonly class DeliveryFieldSnapshot
{
    /** @param string|list<string> $value */
    public function __construct(
        public string $label,
        public string|array $value,
    ) {
        if ($this->label === '' || mb_strlen($this->label, 'UTF-8') > 256) {
            throw new InvalidArgumentException('The delivery field label is invalid.');
        }

        if (is_array($this->value)) {
            if (count($this->value) > 100) {
                throw new InvalidArgumentException('The delivery field value list is invalid.');
            }

            foreach ($this->value as $item) {
                if (strlen($item) > 10000) {
                    throw new InvalidArgumentException('The delivery field value is invalid.');
                }
            }
        } elseif (strlen($this->value) > 10000) {
            throw new InvalidArgumentException('The delivery field value is invalid.');
        }
    }

    /** @return array{label: string, value: string|list<string>} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }
}
