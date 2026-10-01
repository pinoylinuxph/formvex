<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Submission;

use Formvex\Contracts\V1\Submission\SubmissionFieldShape;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Core\Delivery\DeliveryFieldSnapshot;
use Formvex\Core\Delivery\DeliveryMessageSnapshot;
use Formvex\Core\Submission\CanonicalSubmissionHasher;
use Formvex\Spoke\Application\Abuse\SubmissionAbuseService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\Contract\FormConfigurationStore;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\Storage\Contract\StorageCapacityGuard;
use Formvex\Spoke\Domain\Submission\Contract\SubmissionStore;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Domain\Submission\SubmissionAccepted;
use Formvex\Spoke\Domain\Submission\SubmissionClassification;

final readonly class SubmissionService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormConfigurationStore $formConfigurationStore,
        private SubmissionStore $submissionStore,
        private CanonicalSubmissionHasher $hasher,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
        private ?SubmissionAbuseService $abuseService = null,
        private ?InstallationSettingsStore $settingsStore = null,
        private ?StorageCapacityGuard $capacityGuard = null,
    ) {
    }

    public function accept(string $applicationRoot, string $publicFormId, SubmissionRequest $request, ?SubmissionClassification $classification = null, ?string $clientIp = null): SubmissionAccepted
    {
        return $this->acceptInternal($applicationRoot, $publicFormId, $request, $classification, $clientIp, false, null);
    }

    public function acceptQualification(string $applicationRoot, string $publicFormId, SubmissionRequest $request, string $qualificationId, ?string $clientIp = null): SubmissionAccepted
    {
        return $this->acceptInternal($applicationRoot, $publicFormId, $request, null, $clientIp, true, $qualificationId);
    }

    private function acceptInternal(string $applicationRoot, string $publicFormId, SubmissionRequest $request, ?SubmissionClassification $classification, ?string $clientIp, bool $qualification, ?string $qualificationId): SubmissionAccepted
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $configuration = $qualification
            ? $this->formConfigurationStore->findPublished($paths, $publicFormId, $request->configurationVersion)
            : $this->formConfigurationStore->findActive($paths, $publicFormId);

        if ($configuration === null) {
            throw new SubmissionFailure('form_unavailable', $qualification ? 'The published form version is no longer available for qualification. Start a new qualification session.' : 'Formvex could not find an approved active form for this request.');
        }

        $this->assertConfigurationIdentity($configuration, $request);
        $preValidationHash = $this->payloadHash($publicFormId, $configuration, $request, $request->fields);
        $deferredAttemptFailure = null;

        if ($this->abuseService !== null && $clientIp !== null) {
            try {
                $recovered = $this->submissionStore->findAcceptedAttempt($paths, $publicFormId, $request->attemptId, $preValidationHash, $this->clock->now());

                if ($recovered !== null) {
                    return $recovered;
                }
            } catch (SubmissionFailure $failure) {
                if (!in_array($failure->failureCode, ['attempt_conflict', 'attempt_expired'], true)) {
                    throw $failure;
                }

                $deferredAttemptFailure = $failure;
            }

            if ($deferredAttemptFailure === null) {
                $this->abuseService->assertAttemptAllowed($applicationRoot, $clientIp, $publicFormId);
            }
        }

        if ($this->capacityGuard !== null && !$this->capacityGuard->evaluate($paths)->allowed) {
            throw new SubmissionFailure('storage_unavailable', 'Your message could not be accepted because local storage is full or temporarily unavailable. Please try again later.');
        }

        $validatedFields = $this->validateFields($configuration, $request);

        if ($deferredAttemptFailure !== null) {
            throw $deferredAttemptFailure;
        }
        $canonicalFields = $this->omitEmptyArrays($validatedFields);
        $payloadHash = $this->payloadHash($publicFormId, $configuration, $request, $canonicalFields);
        $now = $this->clock->now();
        $classification ??= $this->abuseService !== null && $clientIp !== null
            ? $this->abuseService->classify($applicationRoot, $configuration, $request, $clientIp)
            : SubmissionClassification::NORMAL;

        return $this->submissionStore->accept(
            $paths,
            $configuration,
            $request,
            $canonicalFields,
            $payloadHash,
            $classification,
            $now,
            $this->identifierGenerator->uuidV7($now),
            $this->identifierGenerator->uuidV7($now),
            $this->identifierGenerator->uuidV7($now),
            $this->deliverySnapshot($paths, $configuration, $canonicalFields, $classification),
            $qualificationId,
        );
    }

    /** @param array<string, string|list<string>> $validatedFields */
    private function deliverySnapshot(
        \Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths,
        FormConfigurationRecord $configuration,
        array $validatedFields,
        SubmissionClassification $classification,
    ): DeliveryMessageSnapshot {
        $settings = $this->settingsStore?->get($paths) ?? InstallationSettings::defaults();
        $fields = [];
        $replyTo = null;

        foreach ($configuration->fields as $field) {
            $value = $validatedFields[$field->controlName] ?? '';
            $fields[] = new DeliveryFieldSnapshot($field->displayLabel, $value);

            if ($replyTo === null && $field->controlType === 'email' && is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $replyTo = $value;
            }
        }

        return new DeliveryMessageSnapshot(
            $settings->senderEmail,
            $settings->senderName,
            $configuration->recipient,
            $configuration->subject,
            $classification->value,
            $fields,
            $replyTo,
        );
    }

    /** @param array<string, string|list<string>> $fields */
    private function payloadHash(string $publicFormId, FormConfigurationRecord $configuration, SubmissionRequest $request, array $fields): string
    {
        return $this->hasher->hash([
            'public_form_id' => $publicFormId,
            'page_path' => PageIdentity::fromInput($configuration->page->host, $request->pagePath, $request->formMarker)->path,
            'form_marker' => $request->formMarker,
            'configuration_version' => $request->configurationVersion,
            'fields' => $this->omitEmptyArrays($fields),
            'field_shape' => array_map(
                static fn (SubmissionFieldShape $shape): array => $shape->toArray(),
                $request->fieldShape,
            ),
        ]);
    }

    private function assertConfigurationIdentity(FormConfigurationRecord $configuration, SubmissionRequest $request): void
    {
        if ($request->configurationVersion !== $configuration->versionNumber || $request->formMarker !== $configuration->page->formMarker) {
            throw new SubmissionFailure('configuration_stale', 'This form configuration changed. Refresh the page and try again.');
        }

        try {
            $page = PageIdentity::fromInput($configuration->page->host, $request->pagePath, $request->formMarker);
        } catch (FormConfigurationFailure) {
            throw new SubmissionFailure('request_invalid', 'The submission page path is invalid. Refresh the page and try again.');
        }

        if ($page->path !== $configuration->page->path) {
            throw new SubmissionFailure('configuration_stale', 'This form is not approved for the page that sent the request. Refresh the page and try again.');
        }
    }

    /** @return array<string, string|list<string>> */
    private function validateFields(FormConfigurationRecord $configuration, SubmissionRequest $request): array
    {
        /** @var array<string, FormFieldDefinition> $definitions */
        $definitions = [];
        /** @var array<string, string> $shape */
        $shape = [];

        foreach ($configuration->fields as $field) {
            $definitions[$field->controlName] = $field;
            $shape[$field->controlName] = $field->controlType;
        }

        $submittedShape = [];

        foreach ($request->fieldShape as $entry) {
            if (isset($submittedShape[$entry->controlName]) || ($shape[$entry->controlName] ?? null) !== $entry->controlType) {
                throw new SubmissionFailure('submission_shape_invalid', 'The submitted form structure does not match the approved form. Refresh the page and try again.');
            }

            $submittedShape[$entry->controlName] = $entry->controlType;
        }

        if (count($submittedShape) !== count($shape) || array_diff_key($shape, $submittedShape) !== []) {
            throw new SubmissionFailure('submission_shape_invalid', 'The submitted form structure does not match the approved form. Refresh the page and try again.');
        }

        /** @var array<string, array{code: string, message: string}> $errors */
        $errors = [];
        /** @var array<string, string|list<string>> $validated */
        $validated = [];

        foreach ($request->fields as $controlName => $value) {
            if (!isset($definitions[$controlName])) {
                $errors[$controlName] = ['code' => 'unknown_field', 'message' => 'This control is not part of the approved form.'];

                if (count($errors) >= 20) {
                    break;
                }

                continue;
            }

            $field = $definitions[$controlName];
            $normalized = $this->validateValue($field, $value, $errors);

            if ($normalized !== null) {
                $validated[$controlName] = $normalized;
            }
        }

        foreach ($configuration->fields as $field) {
            if (!array_key_exists($field->controlName, $request->fields) && $field->required) {
                $errors[$field->controlName] = ['code' => 'required', 'message' => 'Complete this required field.'];
            }
        }

        if ($errors !== []) {
            /** @var array<string, array{code: string, message: string}> $boundedErrors */
            $boundedErrors = array_slice($errors, 0, 20, true);
            throw new SubmissionFailure('field_validation_failed', 'Please correct the highlighted fields and try again.', $boundedErrors);
        }

        return $validated;
    }

    /**
     * @param string|list<string> $value
     * @param array<string, array{code: string, message: string}> $errors
     * @return string|list<string>|null
     */
    private function validateValue(FormFieldDefinition $field, string|array $value, array &$errors): string|array|null
    {
        $expectsArray = $field->controlType === 'checkbox';
        $allowsArray = $expectsArray || $field->controlType === 'select';

        if (is_array($value) && !$allowsArray || is_string($value) && $expectsArray) {
            $errors[$field->controlName] = ['code' => 'shape_invalid', 'message' => 'Use the approved control format and try again.'];

            return null;
        }

        if (is_array($value)) {
            if (count($value) > 100) {
                $errors[$field->controlName] = ['code' => 'shape_invalid', 'message' => 'Use the approved control format and try again.'];

                return null;
            }

            foreach ($value as $item) {
                if ($this->stringLength($item) > $field->maxLength) {
                    $errors[$field->controlName] = ['code' => 'too_long', 'message' => 'Shorten this field to the configured maximum length.'];

                    return null;
                }

                if ($field->choices !== [] && !in_array($item, array_map(static fn ($choice): string => $choice->value, $field->choices), true)) {
                    $errors[$field->controlName] = ['code' => 'choice_invalid', 'message' => 'Choose one of the approved options.'];

                    return null;
                }
            }

            if ($field->required && $this->arrayIsEmpty($value)) {
                $errors[$field->controlName] = ['code' => 'required', 'message' => 'Complete this required field.'];

                return null;
            }

            return $value === [] ? null : $value;
        }

        if ($this->stringLength($value) > $field->maxLength) {
            $errors[$field->controlName] = ['code' => 'too_long', 'message' => 'Shorten this field to the configured maximum length.'];

            return null;
        }

        if ($field->choices !== [] && !in_array($value, array_map(static fn ($choice): string => $choice->value, $field->choices), true)) {
            $errors[$field->controlName] = ['code' => 'choice_invalid', 'message' => 'Choose one of the approved options.'];

            return null;
        }

        if ($field->controlType === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field->controlName] = ['code' => 'email_invalid', 'message' => 'Enter a valid email address.'];

            return null;
        }

        if ($field->required && trim($value) === '') {
            $errors[$field->controlName] = ['code' => 'required', 'message' => 'Complete this required field.'];

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string|list<string>> $fields
     * @return array<string, string|list<string>>
     */
    private function omitEmptyArrays(array $fields): array
    {
        /** @var array<string, string|list<string>> $result */
        $result = [];

        foreach ($fields as $key => $value) {
            if (!is_array($value) || $value !== []) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param list<string> $value */
    private function arrayIsEmpty(array $value): bool
    {
        return $value === [] || array_filter($value, static fn (string $item): bool => trim($item) !== '') === [];
    }

    private function stringLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }
}
