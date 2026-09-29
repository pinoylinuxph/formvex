<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Forms;

use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Symfony\Component\HttpFoundation\Request;

final class FormDiscoveryRequestResolver
{
    /** @return array{csrf_token: string, host: string, path: string} */
    public function start(Request $request): array
    {
        $payload = $this->payload($request, ['_token', 'page_host', 'page_path']);

        return [
            'csrf_token' => $this->required($payload, '_token'),
            'host' => $this->required($payload, 'page_host'),
            'path' => $this->required($payload, 'page_path'),
        ];
    }

    /**
     * @return array{
     *     csrf_token: string,
     *     candidate_id: string,
     *     selected_form_index: int,
     *     revision: int,
     *     fields: list<array{
     *         field_key: string,
     *         parameter_key: string,
     *         custom_parameter_key: string,
     *         display_label: string,
     *         required: string,
     *         max_length: string,
     *         choice_labels: list<string>
     *     }>
     * }
     */
    public function apply(Request $request, bool $requiresRevision): array
    {
        $allowed = ['_token', 'candidate_id', 'selected_form_index', 'fields'];

        if ($requiresRevision) {
            $allowed[] = 'revision';
        }

        $payload = $request->request->all();

        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new AdministratorFailure('request_malformed');
            }

            if ($key !== 'fields' && !is_string($value)) {
                throw new AdministratorFailure('request_malformed');
            }
        }

        $selected = $this->required($payload, 'selected_form_index');
        $revision = $requiresRevision ? $this->required($payload, 'revision') : '1';

        if (filter_var($selected, FILTER_VALIDATE_INT) === false || (int) $selected < 0) {
            throw new FormConfigurationFailure('discovery_form_selection_invalid', 'Choose a detected form before applying discovery.');
        }

        if (filter_var($revision, FILTER_VALIDATE_INT) === false || (int) $revision < 1) {
            throw new FormConfigurationFailure('draft_revision_invalid', 'Reload the latest form draft before applying discovery.');
        }

        return [
            'csrf_token' => $this->required($payload, '_token'),
            'candidate_id' => $this->required($payload, 'candidate_id'),
            'selected_form_index' => (int) $selected,
            'revision' => (int) $revision,
            'fields' => $this->fields($payload['fields'] ?? []),
        ];
    }

    /**
     * @param mixed $payload
     * @return list<array{
     *     field_key: string,
     *     parameter_key: string,
     *     custom_parameter_key: string,
     *     display_label: string,
     *     required: string,
     *     max_length: string,
     *     choice_labels: list<string>
     * }>
     */
    private function fields(mixed $payload): array
    {
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new FormConfigurationFailure('discovery_mapping_invalid', 'The field mapping form is malformed. Reload discovery and submit the visible mapping controls again.');
        }

        $fields = [];

        foreach ($payload as $fieldKey => $field) {
            if (!is_string($fieldKey) || preg_match('/^[a-z][a-z0-9_-]{0,119}$/', $fieldKey) !== 1 || !is_array($field) || array_is_list($field)) {
                throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field mapping is malformed. Reload discovery and submit the visible mapping controls again.');
            }

            $normalizedField = [];

            foreach ($field as $key => $value) {
                if (!is_string($key)) {
                    throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field mapping must use named properties. Reload discovery and submit the visible mapping controls again.');
                }

                $normalizedField[$key] = $value;
            }

            $field = $normalizedField;

            $allowed = ['parameter_key', 'custom_parameter_key', 'display_label', 'required', 'max_length', 'choice_labels'];

            foreach (array_keys($field) as $key) {
                if (!in_array($key, $allowed, true)) {
                    throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field mapping contains an unsupported property. Reload discovery and submit the visible mapping controls again.');
                }
            }

            foreach (['parameter_key', 'custom_parameter_key', 'display_label', 'required', 'max_length'] as $key) {
                if (isset($field[$key]) && !is_string($field[$key])) {
                    throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field mapping contains an invalid text value. Reload discovery and submit the visible mapping controls again.');
                }
            }

            $choiceLabels = $field['choice_labels'] ?? [];

            if (!is_array($choiceLabels) || !array_is_list($choiceLabels)) {
                throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field choice label is malformed. Reload discovery and submit the visible mapping controls again.');
            }

            $normalizedChoiceLabels = [];

            foreach ($choiceLabels as $label) {
                if (!is_string($label)) {
                    throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field choice label is malformed. Reload discovery and submit the visible mapping controls again.');
                }

                $normalizedChoiceLabels[] = trim($label);
            }

            $fields[] = [
                'field_key' => $fieldKey,
                'parameter_key' => $this->textValue($field, 'parameter_key'),
                'custom_parameter_key' => $this->textValue($field, 'custom_parameter_key'),
                'display_label' => $this->textValue($field, 'display_label'),
                'required' => $this->textValue($field, 'required'),
                'max_length' => $this->textValue($field, 'max_length'),
                'choice_labels' => $normalizedChoiceLabels,
            ];
        }

        return $fields;
    }

    /** @param array<string, mixed> $field */
    private function textValue(array $field, string $key): string
    {
        $value = $field[$key] ?? '';

        if (!is_string($value)) {
            throw new FormConfigurationFailure('discovery_mapping_invalid', 'A field mapping contains an invalid text value. Reload discovery and submit the visible mapping controls again.');
        }

        return trim($value);
    }

    /**
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function payload(Request $request, array $allowed): array
    {
        $payload = $request->request->all();
        $result = [];

        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                throw new AdministratorFailure('request_malformed');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $payload */
    private function required(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        if (!is_string($value)) {
            throw new AdministratorFailure('request_malformed');
        }

        $value = trim($value);

        if ($value === '') {
            throw new FormConfigurationFailure('request_incomplete', 'The discovery request is missing a required field. Reload the page and submit all visible fields.');
        }

        return $value;
    }
}
