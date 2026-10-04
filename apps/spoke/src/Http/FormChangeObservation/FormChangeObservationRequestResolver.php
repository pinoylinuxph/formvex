<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormChangeObservation;

use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservationFailure;
use Formvex\Spoke\Domain\FormChangeObservation\ObservedFormStructure;
use JsonException;

final class FormChangeObservationRequestResolver
{
    public const SCHEMA_VERSION = 1;

    public function resolve(string $body): ObservedFormStructure
    {
        if ($body === '') {
            throw new FormChangeObservationFailure('request_invalid', 'The form observation request is empty.');
        }

        try {
            $decoded = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FormChangeObservationFailure('request_invalid', 'The form observation request must be valid JSON.');
        }

        $decoded = $this->object($decoded);

        $this->assertKeys($decoded, ['schema_version', 'configuration_version', 'page_path', 'form_marker', 'source_fingerprint', 'controls']);
        $schemaVersion = $decoded['schema_version'] ?? null;
        $configurationVersion = $decoded['configuration_version'] ?? null;
        $pagePath = $decoded['page_path'] ?? null;
        $formMarker = $decoded['form_marker'] ?? null;
        $sourceFingerprint = $decoded['source_fingerprint'] ?? null;
        $controls = $decoded['controls'] ?? null;

        if ($schemaVersion !== self::SCHEMA_VERSION || !is_int($configurationVersion) || $configurationVersion < 1 || !is_string($pagePath) || !is_string($formMarker) || !is_string($sourceFingerprint) || preg_match('/^[0-9a-f]{64}$/', $sourceFingerprint) !== 1) {
            throw new FormChangeObservationFailure('request_invalid', 'The form observation request has an invalid version or page identity.');
        }

        if ($pagePath === '' || strlen($pagePath) > 2048 || !str_starts_with($pagePath, '/') || str_contains($pagePath, "\0") || str_contains($pagePath, '\\') || preg_match('/[\x00-\x1F\x7F]/', $pagePath) === 1) {
            throw new FormChangeObservationFailure('request_invalid', 'The observed page path is invalid.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $formMarker) !== 1) {
            throw new FormChangeObservationFailure('request_invalid', 'The observed form marker is invalid.');
        }

        if (!is_array($controls) || !array_is_list($controls) || count($controls) > 100) {
            throw new FormChangeObservationFailure('request_invalid', 'The observed form controls exceed the supported limit.');
        }

        $observed = [];
        $keys = [];

        foreach ($controls as $index => $control) {
            $control = $this->object($control, 'Observed control ' . ((int) $index + 1) . ' is not a JSON object.');

            $this->assertKeys($control, ['control_name', 'control_type', 'required', 'max_length', 'choice_values']);
            $name = $control['control_name'] ?? null;
            $type = $control['control_type'] ?? null;
            $required = $control['required'] ?? null;
            $maxLength = $control['max_length'] ?? null;
            $choiceValues = $control['choice_values'] ?? [];

            if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_.:\-\[\]]{0,119}$/', $name) !== 1
                || !is_string($type) || !in_array($type, ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox'], true)
                || !is_bool($required) || !is_int($maxLength) || $maxLength < 1 || $maxLength > 10000) {
                throw new FormChangeObservationFailure('request_invalid', 'Observed control ' . ((int) $index + 1) . ' contains an unsupported structure.');
            }

            if (!is_array($choiceValues) || !array_is_list($choiceValues) || count($choiceValues) > 100) {
                throw new FormChangeObservationFailure('request_invalid', 'Observed choices exceed the supported limit.');
            }

            $choices = [];
            foreach ($choiceValues as $choice) {
                if (!is_string($choice) || $choice === '' || strlen($choice) > 256) {
                    throw new FormChangeObservationFailure('request_invalid', 'Observed choice values must be non-empty strings of no more than 256 characters.');
                }
                $choices[] = $choice;
            }

            $key = $name . '|' . $type;
            if (isset($keys[$key])) {
                throw new FormChangeObservationFailure('request_invalid', 'The observed form contains a duplicate control identity.');
            }
            $keys[$key] = true;

            $observed[] = [
                'control_name' => $name,
                'control_type' => $type,
                'required' => $required,
                'max_length' => $maxLength,
                'choice_values' => array_values(array_unique($choices)),
            ];
        }

        return new ObservedFormStructure(self::SCHEMA_VERSION, $configurationVersion, $pagePath, $formMarker, $sourceFingerprint, $observed);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function assertKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new FormChangeObservationFailure('request_invalid', 'The form observation request contains an unsupported property.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $message = 'The form observation request must be a JSON object.'): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new FormChangeObservationFailure('request_invalid', $message);
        }

        $object = [];
        foreach ($value as $key => $property) {
            if (!is_string($key)) {
                throw new FormChangeObservationFailure('request_invalid', $message);
            }
            $object[$key] = $property;
        }

        return $object;
    }
}
