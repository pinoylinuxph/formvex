<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormChangeObservation;

use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;

final class FormChangeObservationFingerprint
{
    /** @param list<FormFieldDefinition> $fields */
    public static function fromFields(array $fields): string
    {
        $canonical = [];

        foreach ($fields as $field) {
            $canonical[] = [
                'control_name' => $field->controlName,
                'control_type' => $field->controlType,
                'required' => $field->required,
                'max_length' => $field->maxLength,
                'choice_values' => array_map(static fn ($choice): string => $choice->value, $field->choices),
            ];
        }

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
