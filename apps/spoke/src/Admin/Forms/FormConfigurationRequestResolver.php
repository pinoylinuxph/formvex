<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Forms;

use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldChoice;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

final class FormConfigurationRequestResolver
{
    public function draft(Request $request, bool $requiresRevision): FormDraftRequest
    {
        $payload = $this->payload($request, [
            '_token',
            'display_name',
            'page_host',
            'page_path',
            'form_marker',
            'recipient',
            'subject',
            'captcha_enabled',
            'captcha_site_key',
            'fields_json',
            'revision',
        ]);
        $revision = null;

        if ($requiresRevision) {
            $revisionValue = $this->stringValue($payload, 'revision');

            if (filter_var($revisionValue, FILTER_VALIDATE_INT) === false || (int) $revisionValue < 1) {
                throw new FormConfigurationFailure('draft_revision_invalid', 'The form draft revision is invalid. Reload the form before saving it.', ['revision' => 'Reload the latest draft.']);
            }

            $revision = (int) $revisionValue;
        }

        $fields = $this->fieldDefinitions($this->stringValue($payload, 'fields_json'));
        $captchaEnabled = ($payload['captcha_enabled'] ?? '0') === '1';

        if (isset($payload['captcha_enabled']) && !in_array($payload['captcha_enabled'], ['0', '1'], true)) {
            throw new FormConfigurationFailure('captcha_enabled_invalid', 'The CAPTCHA enabled value is invalid. Choose enabled or disabled.', ['captcha_enabled' => 'Choose enabled or disabled.']);
        }

        return new FormDraftRequest(
            new FormConfigurationDraftData(
                trim($this->stringValue($payload, 'display_name')),
                PageIdentity::fromInput(
                    $this->stringValue($payload, 'page_host'),
                    $this->stringValue($payload, 'page_path'),
                    $this->stringValue($payload, 'form_marker'),
                ),
                trim($this->stringValue($payload, 'recipient')),
                trim($this->stringValue($payload, 'subject')),
                $fields,
                $captchaEnabled,
                trim($payload['captcha_site_key'] ?? ''),
            ),
            $revision,
            $this->stringValue($payload, '_token'),
        );
    }

    /** @return array{0: string, 1: int} */
    public function revision(Request $request): array
    {
        $payload = $this->payload($request, ['_token', 'revision']);
        $revision = $this->stringValue($payload, 'revision');

        if (filter_var($revision, FILTER_VALIDATE_INT) === false || (int) $revision < 1) {
            throw new FormConfigurationFailure('draft_revision_invalid', 'The form draft revision is invalid. Reload the form before continuing.');
        }

        return [$this->stringValue($payload, '_token'), (int) $revision];
    }

    /** @return array{0: string, 1: int, 2: bool} */
    public function lifecycle(Request $request): array
    {
        $payload = $this->payload($request, ['_token', 'revision', 'confirm_permanent']);
        $revision = $this->stringValue($payload, 'revision');

        if (filter_var($revision, FILTER_VALIDATE_INT) === false || (int) $revision < 1) {
            throw new FormConfigurationFailure('draft_revision_invalid', 'The form draft revision is invalid. Reload the form before continuing.');
        }

        return [$this->stringValue($payload, '_token'), (int) $revision, ($payload['confirm_permanent'] ?? '') === '1'];
    }

    /**
     * @param list<string> $allowedKeys
     * @return array<string, string>
     */
    private function payload(Request $request, array $allowedKeys): array
    {
        $payload = $request->request->all();

        foreach ($payload as $key => $value) {
            if (!in_array($key, $allowedKeys, true) || !is_string($value)) {
                throw new AdministratorFailure('request_malformed');
            }
        }

        $result = [];

        foreach ($payload as $key => $value) {
            $result[$key] = $value;
        }

        return $result;
    }

    /** @param array<string, string> $payload */
    private function stringValue(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            throw new FormConfigurationFailure('request_incomplete', 'The form request is missing a required field. Reload the page and submit all visible fields.');
        }

        return $payload[$key];
    }

    /** @return list<FormFieldDefinition> */
    public function fieldDefinitions(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            return [];
        }

        if (strlen($json) > 262144) {
            throw new FormConfigurationFailure('fields_too_large', 'The field definition document is larger than 256 KiB. Remove unused definitions and try again.', ['fields_json' => 'Keep field definitions below 256 KiB.']);
        }

        try {
            $decoded = json_decode($json, true, 5, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FormConfigurationFailure('fields_json_invalid', 'The field definition document is not valid JSON. Use the example structure shown on the page.');
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new FormConfigurationFailure('fields_json_invalid', 'The field definition document must be a JSON array of field objects.', ['fields_json' => 'Start with [ and provide one object per field.']);
        }

        $fields = [];

        foreach ($decoded as $index => $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new FormConfigurationFailure('fields_json_invalid', 'Field ' . ((int) $index + 1) . ' must be a JSON object.');
            }

            $field = $this->object($value);
            $this->assertKeys($field, [
                'field_key',
                'control_name',
                'control_type',
                'display_label',
                'parameter_key',
                'ordinal',
                'required',
                'max_length',
                'choices',
            ]);
            $required = $field['required'] ?? false;
            $ordinal = $field['ordinal'] ?? $index;
            $maxLength = $field['max_length'] ?? 255;

            if (!is_bool($required) || !is_int($ordinal) || !is_int($maxLength)) {
                throw new FormConfigurationFailure('fields_json_invalid', 'Field ' . ((int) $index + 1) . ' must use boolean required, integer ordinal, and integer max_length values.');
            }

            $fields[] = new FormFieldDefinition(
                $this->fieldString($field, 'field_key'),
                $this->fieldString($field, 'control_name'),
                $this->fieldString($field, 'control_type'),
                $this->fieldString($field, 'display_label'),
                $this->fieldString($field, 'parameter_key'),
                $ordinal,
                $required,
                $maxLength,
                $this->choices($field['choices'] ?? []),
            );
        }

        FormFieldDefinition::validateList($fields);

        return $fields;
    }

    /**
     * @param mixed $fieldChoices
     * @return list<FormFieldChoice>
     */
    private function choices(mixed $fieldChoices): array
    {
        if ($fieldChoices === null || $fieldChoices === []) {
            return [];
        }

        if (!is_array($fieldChoices) || !array_is_list($fieldChoices)) {
            throw new FormConfigurationFailure('field_choices_invalid', 'Field choices must be a JSON array.');
        }

        $choices = [];

        foreach ($fieldChoices as $choice) {
            if (!is_array($choice) || array_is_list($choice)) {
                throw new FormConfigurationFailure('field_choices_invalid', 'Each field choice must be a JSON object.');
            }

            $choiceObject = $this->object($choice);
            $this->assertKeys($choiceObject, ['value', 'label']);
            $choices[] = new FormFieldChoice($this->fieldString($choiceObject, 'value'), $this->fieldString($choiceObject, 'label'));
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function assertKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new FormConfigurationFailure('fields_json_unknown_property', 'The field definition contains an unsupported property. Remove it and try again.');
            }
        }
    }

    /** @param array<string, mixed> $value */
    private function fieldString(array $value, string $key): string
    {
        if (!is_string($value[$key] ?? null)) {
            throw new FormConfigurationFailure('fields_json_invalid', 'Field property ' . $key . ' must be a string.');
        }

        return trim($value[$key]);
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private function object(array $value): array
    {
        $object = [];

        foreach ($value as $key => $property) {
            if (!is_string($key)) {
                throw new FormConfigurationFailure('fields_json_invalid', 'Field definition properties must use string names.');
            }

            $object[$key] = $property;
        }

        return $object;
    }
}
