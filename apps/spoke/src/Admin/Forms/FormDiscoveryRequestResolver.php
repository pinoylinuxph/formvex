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

    /** @return array{csrf_token: string, candidate_id: string, selected_form_index: int, revision: int, fields_json: string} */
    public function apply(Request $request, bool $requiresRevision): array
    {
        $allowed = ['_token', 'candidate_id', 'selected_form_index', 'fields_json'];

        if ($requiresRevision) {
            $allowed[] = 'revision';
        }

        $payload = $this->payload($request, $allowed);
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
            'fields_json' => $this->required($payload, 'fields_json'),
        ];
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

    /** @param array<string, string> $payload */
    private function required(array $payload, string $key): string
    {
        $value = trim($payload[$key] ?? '');

        if ($value === '') {
            throw new FormConfigurationFailure('request_incomplete', 'The discovery request is missing a required field. Reload the page and submit all visible fields.');
        }

        return $value;
    }
}
