<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;

final readonly class FormFieldChoice
{
    public function __construct(
        public string $value,
        public string $label,
    ) {
        if ($this->value === '' || mb_strlen($this->value, 'UTF-8') > 256) {
            throw new FormConfigurationFailure('field_choice_invalid', 'Each approved choice needs a non-empty value of no more than 256 characters.');
        }

        if ($this->label === '' || mb_strlen($this->label, 'UTF-8') > 256) {
            throw new FormConfigurationFailure('field_choice_invalid', 'Each approved choice needs a non-empty label of no more than 256 characters.');
        }
    }
}
