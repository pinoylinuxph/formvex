<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\FormActivation;

use DateInterval;
use DateTimeImmutable;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormActivation\Contract\FormActivationStore;
use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Domain\FormActivation\FormActivationEvidence;
use Formvex\Spoke\Domain\FormActivation\FormActivationStatus;
use Formvex\Spoke\Domain\FormActivation\QualificationStatus;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationState;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use JsonException;

final readonly class FormActivationStatusService
{
    private const QUALIFICATION_TTL_SECONDS = 600;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormConfigurationService $formConfigurationService,
        private FormActivationStore $activationStore,
        private InstallationSettingsStore $settingsStore,
        private Clock $clock,
    ) {
    }

    public function status(string $applicationRoot, string $publicFormId): FormActivationStatus
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $details = $this->formConfigurationService->details($applicationRoot, $publicFormId, true);
        $target = $this->latest($details->publishedVersions, FormConfigurationState::PUBLISHED);
        $active = $this->latest($details->publishedVersions, FormConfigurationState::ACTIVE);
        $settings = $this->settingsStore->get($paths);
        $smtpTest = $this->settingsStore->getTestState($paths);
        $smtpCurrent = $smtpTest->status->value === 'passed' && $smtpTest->testedRevision === $settings->smtpConfigurationRevision;

        if ($target === null) {
            return new FormActivationStatus(null, $active, FormActivationEvidence::empty($active === null ? 0 : $active->versionNumber, $this->clock->now()), $smtpCurrent, false, false, null, null, null);
        }

        $fingerprint = $this->fingerprint($target, $settings);
        $evidence = $this->activationStore->evidence($paths, $target->versionId, $target->versionNumber, $this->clock->now());

        if ($evidence->endToEndFingerprint !== null && !$evidence->isCurrentFor($fingerprint)) {
            $this->activationStore->invalidateEvidence($paths, $target->versionId, 'configuration_changed', $this->clock->now());
            $evidence = $this->activationStore->evidence($paths, $target->versionId, $target->versionNumber, $this->clock->now());
        }

        $qualificationStatus = $evidence->qualificationId === null
            ? null
            : $this->activationStore->qualificationStatus($paths, $evidence->qualificationId, $this->clock->now());

        if ($qualificationStatus === QualificationStatus::SENT && $evidence->endToEndStatus === QualificationStatus::ACCEPTED) {
            $this->activationStore->recordEvidenceSent($paths, $target->versionId, $evidence->qualificationId ?? '', $this->clock->now());
            $evidence = $this->activationStore->evidence($paths, $target->versionId, $target->versionNumber, $this->clock->now());
        }

        if (in_array($qualificationStatus, [QualificationStatus::FAILED, QualificationStatus::UNCERTAIN], true) && $evidence->endToEndStatus === QualificationStatus::ACCEPTED) {
            $this->activationStore->recordEvidenceOutcome($paths, $target->versionId, $evidence->qualificationId ?? '', $qualificationStatus, $qualificationStatus === QualificationStatus::FAILED ? 'delivery_failed' : 'delivery_uncertain', $this->clock->now());
            $evidence = $this->activationStore->evidence($paths, $target->versionId, $target->versionNumber, $this->clock->now());
        }

        $endToEndCurrent = $evidence->endToEndPassed($fingerprint);

        return new FormActivationStatus(
            $target,
            $active,
            $evidence,
            $smtpCurrent,
            $endToEndCurrent,
            $target->state === FormConfigurationState::PUBLISHED && $smtpCurrent && $endToEndCurrent,
            $qualificationStatus,
            $evidence->endToEndFailureCode,
            $this->qualificationExpiry($paths, $evidence),
        );
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

    private function fingerprint(FormConfigurationRecord $version, InstallationSettings $settings): string
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

    private function qualificationExpiry(PrivateStoragePaths $paths, FormActivationEvidence $evidence): ?DateTimeImmutable
    {
        if ($evidence->qualificationId === null) {
            return null;
        }

        $status = $this->activationStore->qualificationStatus($paths, $evidence->qualificationId, $this->clock->now());

        return $status === QualificationStatus::STALE ? null : $evidence->endToEndAcceptedAt?->add(new DateInterval('PT' . self::QUALIFICATION_TTL_SECONDS . 'S'));
    }
}
