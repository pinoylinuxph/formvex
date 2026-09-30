<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\FormActivation;

use DateInterval;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\Submission\SubmissionService;
use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormActivation\Contract\FormActivationStore;
use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Domain\FormActivation\FormActivationStatus;
use Formvex\Spoke\Domain\FormActivation\QualificationAuthorization;
use Formvex\Spoke\Domain\FormActivation\QualificationSession;
use Formvex\Spoke\Domain\FormConfiguration\Contract\FormConfigurationStore;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationState;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use JsonException;

final readonly class FormActivationService
{
    private const CAPABILITY_TTL_SECONDS = 600;

    private const QUALIFICATION_TTL_SECONDS = 600;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormConfigurationStore $formConfigurationStore,
        private FormConfigurationService $formConfigurationService,
        private FormActivationStore $activationStore,
        private FormActivationStatusService $statusService,
        private InstallationSettingsStore $settingsStore,
        private SecurityTokenGenerator $tokenGenerator,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
        private SubmissionService $submissionService,
    ) {
    }

    public function status(string $applicationRoot, string $publicFormId): FormActivationStatus
    {
        return $this->statusService->status($applicationRoot, $publicFormId);
    }

    public function startQualification(string $applicationRoot, string $sessionId, string $publicFormId): QualificationAuthorization
    {
        if ($sessionId === '') {
            throw new FormActivationFailure('authentication_required', 'Sign in to the local portal before starting qualification.');
        }

        $target = $this->target($applicationRoot, $publicFormId);
        $paths = $this->paths($applicationRoot);
        $settings = $this->settingsStore->get($paths);
        $token = $this->tokenGenerator->generate(32);
        $capabilityId = $this->identifierGenerator->uuidV7($this->clock->now());
        $createdAt = $this->clock->now();
        $expiresAt = $createdAt->add(new DateInterval('PT' . self::CAPABILITY_TTL_SECONDS . 'S'));
        $this->activationStore->createCapability($paths, $capabilityId, $this->tokenGenerator->hash($token), $this->tokenGenerator->hash($sessionId), $target, $this->fingerprint($target, $settings), $createdAt, $expiresAt);
        $this->settingsStore->recordAudit($paths, 'spoke.form_activation.qualification_started', 'success', $createdAt);

        return new QualificationAuthorization(
            $capabilityId,
            $token,
            'https://' . $target->page->host . $target->page->path . '#formvex_qualification=' . rawurlencode($token),
            $expiresAt,
            $target->publicId,
            $target->versionNumber,
            $target->page->formMarker,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: QualificationSession, 1: string, 2: FormConfigurationRecord}
     */
    public function redeemQualification(string $applicationRoot, string $token, string $host, array $payload, int $payloadBytes): array
    {
        if ($token === '' || strlen($token) > 512) {
            throw new FormActivationFailure('qualification_not_authorized', 'The qualification authorization is missing or invalid. Start a new qualification session from the Forms portal.');
        }

        $paths = $this->paths($applicationRoot);
        $settings = $this->settingsStore->get($paths);

        if ($payloadBytes > $settings->discoveryPayloadLimitBytes) {
            throw new FormActivationFailure('qualification_payload_too_large', 'The qualification page returned more metadata than the configured limit. Reduce the number of controls and try again.');
        }

        $pagePath = $payload['page_path'] ?? null;
        $forms = $payload['forms'] ?? null;

        if (!is_string($pagePath) || !is_array($forms) || !array_is_list($forms) || count($forms) > 25) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request must include one valid page path and a bounded form list.');
        }

        $markers = [];
        foreach ($forms as $form) {
            if (!is_array($form) || array_is_list($form) || array_diff(array_keys($form), ['form_marker', 'field_shape']) !== []) {
                throw new FormActivationFailure('qualification_request_invalid', 'The qualification form metadata contains an unsupported property. Update the installed website script and try again.');
            }

            $marker = $form['form_marker'] ?? null;
            if (!is_string($marker) || preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/', $marker) !== 1) {
                throw new FormActivationFailure('qualification_request_invalid', 'The qualification form marker is invalid. Update the installed website script and try again.');
            }
            $markers[] = $marker;
        }

        $capabilityPage = PageIdentity::fromInput($host, $pagePath, 'qualification');
        $qualificationToken = $this->tokenGenerator->generate(32);
        $qualificationId = $this->identifierGenerator->uuidV7($this->clock->now());
        $expiresAt = $this->clock->now()->add(new DateInterval('PT' . self::QUALIFICATION_TTL_SECONDS . 'S'));
        $session = $this->activationStore->redeemCapability($paths, $this->tokenGenerator->hash($token), $capabilityPage, $markers, $qualificationId, $this->tokenGenerator->hash($qualificationToken), $expiresAt, $this->clock->now());
        $version = $this->formConfigurationStore->findPublished($paths, $session->publicFormId, $session->versionNumber);

        if ($version === null) {
            throw new FormActivationFailure('qualification_not_authorized', 'The selected published version is no longer available. Start a new qualification session.');
        }

        $this->settingsStore->recordAudit($paths, 'spoke.form_activation.qualification_redeemed', 'success', $this->clock->now());

        return [$session, $qualificationToken, $version];
    }

    public function submitQualification(string $applicationRoot, string $qualificationToken, string $host, SubmissionRequest $request, ?string $clientIp = null): \Formvex\Spoke\Domain\Submission\SubmissionAccepted
    {
        $paths = $this->paths($applicationRoot);
        $session = $this->activationStore->findSessionByToken($paths, $this->tokenGenerator->hash($qualificationToken), $this->clock->now());

        if ($session === null || $session->submittedAt !== null || $session->page->host !== strtolower(rtrim($host, '.')) || $session->page->path !== $request->pagePath || $session->page->formMarker !== $request->formMarker) {
            throw new FormActivationFailure('qualification_not_authorized', 'This qualification session is invalid for the selected form. Start a new qualification session from the Forms portal.');
        }

        $accepted = $this->submissionService->acceptQualification($applicationRoot, $session->publicFormId, $request, $session->qualificationId, $clientIp);
        $this->activationStore->recordAccepted($paths, $session->qualificationId, $accepted->submissionId ?? '', $this->clock->now());
        $version = $this->formConfigurationStore->findPublished($paths, $session->publicFormId, $session->versionNumber);

        if ($version === null) {
            throw new FormActivationFailure('qualification_not_authorized', 'The published version changed before qualification was recorded. Start a new qualification session from the Forms portal.');
        }

        $this->activationStore->recordEvidenceAccepted($paths, $version->versionId, $session->evidenceFingerprint, $session->qualificationId, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_activation.end_to_end_evidence_recorded', 'accepted', $this->clock->now());

        return $accepted;
    }

    public function activate(string $applicationRoot, string $publicFormId): FormConfigurationRecord
    {
        $status = $this->status($applicationRoot, $publicFormId);

        if ($status->targetVersion === null) {
            throw new FormActivationFailure('activation_version_missing', 'Publish a form version before activating it.');
        }

        if (!$status->smtpTestCurrent) {
            throw new FormActivationFailure('activation_smtp_evidence_missing', 'Run a successful SMTP test from Settings before activating this form.');
        }

        if (!$status->endToEndCurrent) {
            throw new FormActivationFailure('activation_end_to_end_evidence_missing', 'Complete the browser qualification and wait for the synthetic message to be accepted by SMTP before activating this form.');
        }

        $paths = $this->paths($applicationRoot);
        $activated = $this->formConfigurationStore->activatePublished($paths, $publicFormId, $status->targetVersion->versionNumber, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_activation.activated', 'success', $this->clock->now());

        return $activated;
    }

    public function disable(string $applicationRoot, string $publicFormId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->formConfigurationStore->disableActive($paths, $publicFormId, $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.form_activation.disabled', 'success', $this->clock->now());
    }

    private function target(string $applicationRoot, string $publicFormId): FormConfigurationRecord
    {
        $details = $this->formConfigurationService->details($applicationRoot, $publicFormId, true);
        $target = $this->latest($details->publishedVersions, FormConfigurationState::PUBLISHED);

        if ($target === null) {
            throw new FormActivationFailure('published_version_unavailable', 'Publish the current form draft before starting browser qualification.');
        }

        return $target;
    }

    /** @param list<FormConfigurationRecord> $versions */
    private function latest(array $versions, FormConfigurationState $state): ?FormConfigurationRecord
    {
        foreach ($versions as $version) {
            if ($version->state === $state) {
                return $version;
            }
        }

        return null;
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function fingerprint(FormConfigurationRecord $version, \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings $settings): string
    {
        $fields = array_map(static fn ($field): array => [
            'field_key' => $field->fieldKey,
            'control_name' => $field->controlName,
            'control_type' => $field->controlType,
            'display_label' => $field->displayLabel,
            'parameter_key' => $field->parameterKey,
            'ordinal' => $field->ordinal,
            'required' => $field->required,
            'max_length' => $field->maxLength,
            'choices' => array_map(static fn ($choice): array => ['value' => $choice->value, 'label' => $choice->label], $field->choices),
        ], $version->fields);
        try {
            return hash('sha256', json_encode([
                'public_form_id' => $version->publicId,
                'version' => $version->versionNumber,
                'page' => [$version->page->host, $version->page->path, $version->page->formMarker],
                'recipient' => $version->recipient,
                'subject' => $version->subject,
                'captcha' => [$version->captchaEnabled, $version->captchaSiteKey],
                'fields' => $fields,
                'website_aliases' => [$settings->bareDomain, $settings->wwwAlias],
                'sender' => [$settings->senderEmail, $settings->senderName],
                'smtp' => [$settings->smtpHost, $settings->smtpPort, $settings->smtpEncryption->value, $settings->smtpUsername, $settings->smtpTimeoutSeconds, $settings->smtpConfigurationRevision],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (JsonException) {
            throw new FormActivationFailure('activation_fingerprint_failed', 'The form configuration could not be fingerprinted safely. The form remains inactive.');
        }
    }

}
