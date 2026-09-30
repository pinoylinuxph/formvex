<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormActivation;

use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use JsonException;

final class QualificationRequestResolver
{
    /** @return array{token: string, submissionBody: string} */
    public function resolve(string $body): array
    {
        if ($body === '') {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request body is empty. Reload the qualification page and try again.');
        }

        try {
            $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request is not valid JSON. Reload the qualification page and try again.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request must be one JSON object. Reload the qualification page and try again.');
        }

        $token = $decoded['qualification_token'] ?? null;

        if (!is_string($token) || $token === '' || strlen($token) > 512) {
            throw new FormActivationFailure('qualification_not_authorized', 'The qualification session is missing or invalid. Start a new qualification session from the Forms portal.');
        }

        unset($decoded['qualification_token']);

        $allowed = [
            'schema_version',
            'page_path',
            'form_marker',
            'configuration_version',
            'attempt_id',
            'fields',
            'field_shape',
            'honeypot',
            'captcha_token',
        ];

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new FormActivationFailure('qualification_request_invalid', 'The qualification submission contains an unsupported property. Reload the qualification page and try again.');
            }
        }

        try {
            $submissionBody = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request could not be read safely. Reload the qualification page and try again.');
        }

        return ['token' => $token, 'submissionBody' => $submissionBody];
    }
}
