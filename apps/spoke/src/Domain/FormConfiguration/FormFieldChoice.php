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
        if ($this->value === '' || strlen($this->value) > 255) {
            throw new FormConfigurationFailure('field_choice_invalid', 'Each approved choice needs a non-empty value of no more than 255 characters.');
        }

        if ($this->label === '' || strlen($this->label) > 255) {
            throw new FormConfigurationFailure('field_choice_invalid', 'Each approved choice needs a non-empty label of no more than 255 characters.');
        }
    }
}
