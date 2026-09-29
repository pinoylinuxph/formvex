<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\FormDiscovery;

use DateInterval;
use DateTimeImmutable;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldChoice;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormDiscovery\Contract\FormDiscoveryStore;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryAuthorization;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryCandidate;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryControl;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryPayload;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;

final readonly class FormDiscoveryService
{
    private const CAPABILITY_TTL_SECONDS = 600;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormDiscoveryStore $store,
        private FormConfigurationService $formConfigurationService,
        private InstallationSettingsStore $settingsStore,
        private SecurityTokenGenerator $tokenGenerator,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function begin(string $applicationRoot, string $sessionId, string $host, string $path): DiscoveryAuthorization
    {
        if ($sessionId === '') {
            throw new FormDiscoveryFailure('authentication_required', 'Sign in to the local portal before starting form discovery.');
        }

        $paths = $this->paths($applicationRoot);
        $page = PageIdentity::fromInput($host, $path, 'discovery');
        $this->assertConfiguredHost($this->settingsStore->get($paths), $page->host);
        $token = $this->tokenGenerator->generate(32);
        $capabilityId = $this->identifierGenerator->uuidV7($this->clock->now());
        $createdAt = $this->clock->now();
        $expiresAt = $createdAt->add(new DateInterval('PT' . self::CAPABILITY_TTL_SECONDS . 'S'));
        $this->store->createCapability($paths, $capabilityId, $this->tokenGenerator->hash($token), $this->sessionHash($sessionId), $page, $createdAt, $expiresAt);
        $this->settingsStore->recordAudit($paths, 'spoke.form_discovery.started', 'success', $createdAt);

        return new DiscoveryAuthorization(
            $capabilityId,
            $token,
            'https://' . $page->host . $page->path . '#formvex_discovery=' . rawurlencode($token),
            $expiresAt,
        );
    }

    /** @param array<string, mixed> $payload */
    public function redeem(string $applicationRoot, string $token, string $host, array $payload, int $payloadBytes): DiscoveryCandidate
    {
        if ($token === '' || strlen($token) > 512) {
            throw new FormDiscoveryFailure('discovery_not_authorized', 'The discovery authorization is missing or invalid. Start a new discovery session from the Forms portal.');
        }

        $paths = $this->paths($applicationRoot);
        $settings = $this->settingsStore->get($paths);

        if ($payloadBytes > $settings->discoveryPayloadLimitBytes) {
            throw new FormDiscoveryFailure('discovery_payload_too_large', 'The page returned more discovery metadata than the configured limit. Increase the discovery limit in Settings or reduce the number of forms and choices on the page.', ['payload' => 'The complete page discovery payload is too large.']);
        }

        $pagePath = $payload['page_path'] ?? null;

        if (!is_string($pagePath)) {
            throw new FormDiscoveryFailure('discovery_page_invalid', 'The discovery script did not provide a valid page path.');
        }

        $page = PageIdentity::fromInput($host, $pagePath, 'discovery');
        $this->assertConfiguredHost($settings, $page->host);
        $discovery = DiscoveryPayload::fromArray($payload, $page->host, $payloadBytes);
        $candidateId = $this->identifierGenerator->uuidV7($this->clock->now());
        $candidate = $this->store->redeemAndCreateCandidate($paths, $this->tokenGenerator->hash($token), $page, $candidateId, $discovery, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_discovery.redeemed', 'success', $this->clock->now());

        return $candidate;
    }

    /** @return list<DiscoveryCandidate> */
    public function candidates(string $applicationRoot, string $sessionId): array
    {
        return $this->store->listCandidates($this->paths($applicationRoot), $this->sessionHash($sessionId), $this->clock->now());
    }

    public function candidate(string $applicationRoot, string $sessionId, string $candidateId): DiscoveryCandidate
    {
        $candidate = $this->store->findCandidate($this->paths($applicationRoot), $this->sessionHash($sessionId), $candidateId, $this->clock->now());

        if ($candidate === null) {
            throw new FormDiscoveryFailure('discovery_candidate_unavailable', 'The discovery candidate is missing or expired. Start a new discovery session from the Forms portal.');
        }

        return $candidate;
    }

    /** @param list<array{field_key: string, parameter_key: string, custom_parameter_key: string, display_label: string, required: string, max_length: string, choice_labels: list<string>}> $fieldInputs */
    public function applyCandidate(
        string $applicationRoot,
        string $sessionId,
        string $candidateId,
        ?string $publicFormId,
        int $selectedFormIndex,
        int $expectedRevision,
        array $fieldInputs,
    ): FormConfigurationDetails {
        $paths = $this->paths($applicationRoot);
        $candidate = $this->candidate($applicationRoot, $sessionId, $candidateId);
        $form = $candidate->forms[$selectedFormIndex] ?? null;

        if ($form === null) {
            throw new FormDiscoveryFailure('discovery_form_selection_invalid', 'Choose one of the forms returned by discovery before applying the mapping.');
        }

        if ($form->ambiguous || $form->unsupportedControls !== []) {
            throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Resolve every ambiguous or unsupported control before applying this form mapping.');
        }

        $fields = $this->fieldsFromInput($form->controls, $fieldInputs);
        $this->assertFieldsMatch($form->controls, $fields);
        $page = PageIdentity::fromInput($candidate->host, $candidate->path, $form->formMarker);

        if ($publicFormId === null) {
            $details = $this->formConfigurationService->create(
                $applicationRoot,
                new FormConfigurationDraftData($form->displayName, $page, '', '', $fields),
            );
        } else {
            $current = $this->formConfigurationService->details($applicationRoot, $publicFormId);
            $details = $this->formConfigurationService->update(
                $applicationRoot,
                $publicFormId,
                $expectedRevision,
                new FormConfigurationDraftData($current->draft->displayName, $page, $current->draft->recipient, $current->draft->subject, $fields),
            );
        }

        $this->store->markCandidateApplied($paths, $this->sessionHash($sessionId), $candidateId, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_discovery.mapping_applied', 'success', $this->clock->now());

        return $details;
    }

    public function discard(string $applicationRoot, string $sessionId, string $candidateId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->store->markCandidateDiscarded($paths, $this->sessionHash($sessionId), $candidateId, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_discovery.candidate_discarded', 'success', $this->clock->now());
    }

    /**
     * @param list<DiscoveryControl> $controls
     * @param list<FormFieldDefinition> $fields
     */
    private function assertFieldsMatch(array $controls, array $fields): void
    {
        if (count($controls) !== count($fields)) {
            throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Every supported discovered control must have one reviewed mapping before applying the form.');
        }

        $fieldsByName = [];

        foreach ($fields as $field) {
            $fieldsByName[$field->controlName] = $field;
        }

        foreach ($controls as $control) {
            $field = $fieldsByName[$control->controlName] ?? null;

            if ($field === null || $field->controlType !== $control->controlType) {
                throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Each mapped control must match the discovered control name and type.', ['fields' => 'Review every discovered control and keep its control name and type unchanged.']);
            }

            if ($field->parameterKey === 'unmapped') {
                throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Every discovered control needs a confirmed built-in or custom parameter before applying the form.', ['fields' => 'Choose a built-in or custom parameter for every discovered control.']);
            }

            $expectedChoices = array_column($control->choices, 'value');
            $actualChoices = array_map(static fn ($choice): string => $choice->value, $field->choices);
            sort($expectedChoices);
            sort($actualChoices);

            if ($expectedChoices !== $actualChoices) {
                throw new FormDiscoveryFailure('discovery_choices_changed', 'Approved choice values must remain the values detected in the website HTML. You may edit their labels.', ['fields' => 'Restore the detected choice values before applying the mapping.']);
            }
        }
    }

    /**
     * @param list<DiscoveryControl> $controls
     * @param list<array{field_key: string, parameter_key: string, custom_parameter_key: string, display_label: string, required: string, max_length: string, choice_labels: list<string>}> $inputs
     * @return list<FormFieldDefinition>
     */
    private function fieldsFromInput(array $controls, array $inputs): array
    {
        $inputByKey = [];

        foreach ($inputs as $input) {
            if (isset($inputByKey[$input['field_key']])) {
                throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Each discovered control must have one mapping. Remove duplicate field mappings and try again.');
            }

            $inputByKey[$input['field_key']] = $input;
        }

        if (count($inputByKey) !== count($controls)) {
            throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Every discovered control needs a confirmed built-in or custom parameter before applying the form.');
        }

        $fields = [];

        foreach ($controls as $ordinal => $control) {
            $input = $inputByKey[$control->discoveryKey] ?? null;

            if ($input === null) {
                throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Every discovered control needs a confirmed built-in or custom parameter before applying the form.');
            }

            $parameterKey = $input['parameter_key'];

            if ($parameterKey === 'custom') {
                $parameterKey = $input['custom_parameter_key'];
            }

            if ($parameterKey === '' || $parameterKey === 'unmapped') {
                throw new FormDiscoveryFailure('discovery_mapping_incomplete', 'Every discovered control needs a confirmed built-in or custom parameter before applying the form.');
            }

            if (!in_array($input['required'], ['0', '1'], true)) {
                throw new FormDiscoveryFailure('discovery_mapping_invalid', 'Choose whether each discovered control is required before applying the form.');
            }

            if (filter_var($input['max_length'], FILTER_VALIDATE_INT) === false) {
                throw new FormDiscoveryFailure('discovery_mapping_invalid', 'Each discovered control needs a numeric maximum length between 1 and 10,000.');
            }

            $choiceLabels = $input['choice_labels'];

            if (count($choiceLabels) !== count($control->choices)) {
                throw new FormDiscoveryFailure('discovery_mapping_invalid', 'Review every detected choice label before applying the form.');
            }

            $choices = [];

            foreach ($control->choices as $choiceIndex => $choice) {
                $label = $choiceLabels[$choiceIndex] ?? '';

                if ($label === '') {
                    throw new FormDiscoveryFailure('discovery_mapping_invalid', 'Every detected choice needs a non-empty label before applying the form.');
                }

                $choices[] = new FormFieldChoice($choice['value'], $label);
            }

            $fields[] = new FormFieldDefinition(
                $control->discoveryKey,
                $control->controlName,
                $control->controlType,
                $input['display_label'],
                $parameterKey,
                $ordinal,
                $input['required'] === '1',
                (int) $input['max_length'],
                $choices,
            );
        }

        return $fields;
    }

    private function assertConfiguredHost(InstallationSettings $settings, string $host): void
    {
        $aliases = array_values(array_filter([$settings->bareDomain, $settings->wwwAlias], static fn (?string $alias): bool => $alias !== null && $alias !== ''));

        if (!in_array($host, $aliases, true)) {
            throw new FormDiscoveryFailure('page_host_not_allowed', 'Discovery is allowed only for a configured website HTTPS alias. Add the alias in Settings and try again.');
        }
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function sessionHash(string $sessionId): string
    {
        if ($sessionId === '') {
            throw new FormDiscoveryFailure('authentication_required', 'Sign in to the local portal before reviewing discovery.');
        }

        return $this->tokenGenerator->hash($sessionId);
    }
}
