<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;

final readonly class FormFieldDefinition
{
    /** @param list<FormFieldChoice> $choices */
    public function __construct(
        public string $fieldKey,
        public string $controlName,
        public string $controlType,
        public string $displayLabel,
        public string $parameterKey,
        public int $ordinal,
        public bool $required,
        public int $maxLength,
        public array $choices = [],
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $this->fieldKey) !== 1) {
            throw new FormConfigurationFailure('field_key_invalid', 'Each field key must start with a letter and contain only letters, numbers, dots, colons, underscores, or hyphens.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:\-\[\]]{0,119}$/', $this->controlName) !== 1) {
            throw new FormConfigurationFailure('field_name_invalid', 'Each control name must be a stable HTML field name.');
        }

        if (!in_array($this->controlType, ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox'], true)) {
            throw new FormConfigurationFailure('field_type_invalid', 'The field control type is not supported by Formvex.');
        }

        if ($this->displayLabel === '' || strlen($this->displayLabel) > 160) {
            throw new FormConfigurationFailure('field_label_invalid', 'Each field needs a display label of no more than 160 characters.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/', $this->parameterKey) !== 1) {
            throw new FormConfigurationFailure('parameter_key_invalid', 'Each parameter key must start with a lowercase letter and use lowercase letters, numbers, and underscores.');
        }

        if ($this->ordinal < 0 || $this->ordinal > 49) {
            throw new FormConfigurationFailure('field_order_invalid', 'Field order must be between 0 and 49.');
        }

        if ($this->maxLength < 1 || $this->maxLength > 10000) {
            throw new FormConfigurationFailure('field_length_invalid', 'Each field length must be between 1 and 10,000 characters.');
        }

        foreach ($this->choices as $choice) {
            if ($choice->value === '' || $choice->label === '') {
                throw new FormConfigurationFailure('field_choice_invalid', 'Field choices must contain a value and label.');
            }
        }
    }

    /** @param list<FormFieldDefinition> $fields */
    public static function validateList(array $fields): void
    {
        if (count($fields) > 50) {
            throw new FormConfigurationFailure('field_count_exceeded', 'A form can contain no more than 50 configured fields.', ['fields_json' => 'Remove fields until 50 or fewer remain.']);
        }

        $fieldKeys = [];
        $controlNames = [];

        foreach ($fields as $field) {
            if (isset($fieldKeys[$field->fieldKey])) {
                throw new FormConfigurationFailure('field_key_duplicate', 'Each configured field key must be unique.');
            }

            if (isset($controlNames[$field->controlName])) {
                throw new FormConfigurationFailure('field_name_duplicate', 'Each configured HTML control name must be unique.');
            }

            $fieldKeys[$field->fieldKey] = true;
            $controlNames[$field->controlName] = true;
        }
    }
}
