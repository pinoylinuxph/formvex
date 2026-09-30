<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\Submission;

use Formvex\Contracts\V1\Submission\SubmissionFieldShape;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use JsonException;

final class SubmissionRequestResolver
{
    public const MAX_BODY_BYTES = 131072;

    private const MAX_FIELD_COUNT = 100;

    private const MAX_ARRAY_ITEMS = 100;

    private const MAX_STRING_BYTES = 10000;

    /** @throws SubmissionFailure */
    public function resolve(string $body): SubmissionRequest
    {
        if ($body === '') {
            throw new SubmissionFailure('request_invalid', 'The submission request body is empty. Send one JSON object.');
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new SubmissionFailure('request_too_large', 'The submission request exceeds the 128 KiB limit. Shorten the message and try again.');
        }

        try {
            $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SubmissionFailure('request_invalid', 'The submission request is not valid JSON. Send one JSON object with the documented fields.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new SubmissionFailure('request_invalid', 'The submission request must be one JSON object.');
        }

        $allowed = ['schema_version', 'page_path', 'form_marker', 'configuration_version', 'attempt_id', 'fields', 'field_shape', 'honeypot', 'captcha_token'];

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new SubmissionFailure('request_invalid', 'The submission request contains an unsupported property. Remove it and try again.');
            }
        }

        $schemaVersion = $decoded['schema_version'] ?? null;
        $pagePath = $decoded['page_path'] ?? null;
        $formMarker = $decoded['form_marker'] ?? null;
        $configurationVersion = $decoded['configuration_version'] ?? null;
        $attemptId = $decoded['attempt_id'] ?? null;
        $fields = $decoded['fields'] ?? null;
        $fieldShape = $decoded['field_shape'] ?? null;
        $honeypot = $this->protocolValue($decoded, 'honeypot');
        $captchaToken = $this->protocolValue($decoded, 'captcha_token');

        if (!is_int($schemaVersion) || $schemaVersion !== SubmissionRequest::SCHEMA_VERSION) {
            throw new SubmissionFailure('request_invalid', 'The submission schema version is not supported. Reload the page and try again.');
        }

        if (!is_string($pagePath) || $pagePath === '' || strlen($pagePath) > 2048 || !str_starts_with($pagePath, '/') || str_contains($pagePath, "\0") || str_contains($pagePath, '\\') || preg_match('/[\x00-\x1F\x7F]/', $pagePath) === 1) {
            throw new SubmissionFailure('request_invalid', 'The submission page path is invalid. Reload the page and try again.');
        }

        if (!is_string($formMarker) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $formMarker) !== 1) {
            throw new SubmissionFailure('request_invalid', 'The submission form marker is invalid. Reload the page and try again.');
        }

        if (!is_int($configurationVersion) || $configurationVersion < 1) {
            throw new SubmissionFailure('request_invalid', 'The submission configuration version is invalid. Reload the page and try again.');
        }

        if (!is_string($attemptId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $attemptId) !== 1) {
            throw new SubmissionFailure('request_invalid', 'The submission attempt reference is invalid. Reload the page and try again.');
        }

        $normalizedFields = $this->fields($fields);
        $normalizedShape = $this->fieldShape($fieldShape);

        return new SubmissionRequest(
            $schemaVersion,
            $pagePath,
            $formMarker,
            $configurationVersion,
            strtolower($attemptId),
            $normalizedFields,
            $normalizedShape,
            $honeypot,
            $captchaToken,
        );
    }

    /** @param array<mixed, mixed> $decoded */
    private function protocolValue(array $decoded, string $key): ?string
    {
        if (!array_key_exists($key, $decoded)) {
            return null;
        }

        $value = $decoded[$key];

        if (!is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new SubmissionFailure('request_invalid', 'The submission ' . str_replace('_', ' ', $key) . ' is invalid or too long. Reload the page and try again.');
        }

        return $value;
    }

    /** @return array<string, string|list<string>> */
    private function fields(mixed $fields): array
    {
        if (!is_array($fields) || array_is_list($fields) || count($fields) > self::MAX_FIELD_COUNT) {
            throw new SubmissionFailure('request_invalid', 'The submission fields must be an object containing no more than 100 controls.');
        }

        $normalized = [];

        foreach ($fields as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.:\-\[\]]{0,119}$/', $key) !== 1) {
                throw new SubmissionFailure('request_invalid', 'A submitted control name is invalid. Reload the page and try again.');
            }

            if (is_string($value)) {
                $this->assertStringSize($value);
                $normalized[$key] = $value;
                continue;
            }

            if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ARRAY_ITEMS) {
                throw new SubmissionFailure('request_invalid', 'Each submitted control must contain a string or an ordered list of strings.');
            }

            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new SubmissionFailure('request_invalid', 'Each submitted control value must be a string.');
                }

                $this->assertStringSize($item);
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /** @return list<SubmissionFieldShape> */
    private function fieldShape(mixed $fieldShape): array
    {
        if (!is_array($fieldShape) || !array_is_list($fieldShape) || count($fieldShape) > self::MAX_FIELD_COUNT) {
            throw new SubmissionFailure('request_invalid', 'The submission field shape must be an ordered list of no more than 100 controls.');
        }

        $seen = [];
        $normalized = [];
        $allowedTypes = ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox'];

        foreach ($fieldShape as $shape) {
            if (!is_array($shape) || array_is_list($shape) || array_diff(array_keys($shape), ['control_name', 'control_type']) !== []) {
                throw new SubmissionFailure('request_invalid', 'Each field-shape entry must contain only a control name and control type.');
            }

            $controlName = $shape['control_name'] ?? null;
            $controlType = $shape['control_type'] ?? null;

            if (!is_string($controlName) || preg_match('/^[A-Za-z][A-Za-z0-9_.:\-\[\]]{0,119}$/', $controlName) !== 1 || !is_string($controlType) || !in_array($controlType, $allowedTypes, true)) {
                throw new SubmissionFailure('request_invalid', 'Each field-shape entry must identify a supported named control.');
            }

            $shapeKey = $controlName . ':' . $controlType;

            if (isset($seen[$shapeKey])) {
                throw new SubmissionFailure('request_invalid', 'The submission field shape contains a duplicate control.');
            }

            $seen[$shapeKey] = true;
            $normalized[] = new SubmissionFieldShape($controlName, $controlType);
        }

        return $normalized;
    }

    private function assertStringSize(string $value): void
    {
        if (strlen($value) > self::MAX_STRING_BYTES) {
            throw new SubmissionFailure('request_invalid', 'A submitted control value exceeds the supported request size. Shorten it and try again.');
        }
    }
}
