<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\FormConfiguration;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\Contract\FormConfigurationStore;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationSummary;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormConfiguration\PublicFormResolution;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;

final readonly class FormConfigurationService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private FormConfigurationStore $store,
        private InstallationSettingsStore $settingsStore,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    /** @return list<FormConfigurationSummary> */
    public function list(string $applicationRoot, bool $includeTrash = false): array
    {
        return $this->store->list($this->paths($applicationRoot), $includeTrash);
    }

    public function details(string $applicationRoot, string $publicId, bool $includeTrash = false): FormConfigurationDetails
    {
        $details = $this->store->find($this->paths($applicationRoot), $publicId, $includeTrash);

        if ($details === null) {
            throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the requested form configuration.');
        }

        return $details;
    }

    public function create(string $applicationRoot, FormConfigurationDraftData $data): FormConfigurationDetails
    {
        $paths = $this->paths($applicationRoot);
        $this->validateDraft($paths, $data, null);
        $publicId = $this->identifierGenerator->uuidV7($this->clock->now());
        $details = $this->store->createDraft($paths, $publicId, $data, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.draft_created', 'success');

        return $details;
    }

    public function update(string $applicationRoot, string $publicId, int $expectedRevision, FormConfigurationDraftData $data): FormConfigurationDetails
    {
        $paths = $this->paths($applicationRoot);
        $this->details($applicationRoot, $publicId);
        $this->validateDraft($paths, $data, $publicId);
        $details = $this->store->updateDraft($paths, $publicId, $expectedRevision, $data, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.draft_updated', 'success');

        return $details;
    }

    public function publish(string $applicationRoot, string $publicId, int $expectedRevision): FormConfigurationRecord
    {
        $paths = $this->paths($applicationRoot);
        $details = $this->details($applicationRoot, $publicId);
        $this->validatePublication($paths, $details->draft);
        $published = $this->store->publishDraft($paths, $publicId, $expectedRevision, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.published', 'success');

        return $published;
    }

    public function trash(string $applicationRoot, string $publicId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->details($applicationRoot, $publicId);
        $this->store->trash($paths, $publicId, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.trashed', 'success');
    }

    public function restore(string $applicationRoot, string $publicId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->details($applicationRoot, $publicId, true);
        $this->store->restore($paths, $publicId, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.restored', 'success');
    }

    public function hardDelete(string $applicationRoot, string $publicId): void
    {
        $paths = $this->paths($applicationRoot);
        $this->details($applicationRoot, $publicId, true);
        $this->store->hardDelete($paths, $publicId, $this->clock->now());
        $this->audit($paths, 'spoke.form_configuration.hard_deleted', 'success');
    }

    public function resolvePublic(string $applicationRoot, string $host, string $path, string $formMarker): ?PublicFormResolution
    {
        $paths = $this->paths($applicationRoot);
        $page = PageIdentity::fromInput($host, $path, $formMarker);
        $this->assertConfiguredHost($this->settingsStore->get($paths), $page->host);

        return $this->store->resolveActive($paths, $page);
    }

    private function validateDraft(PrivateStoragePaths $paths, FormConfigurationDraftData $data, ?string $exceptPublicId): void
    {
        $this->assertConfiguredHost($this->settingsStore->get($paths), $data->page->host);

        if ($this->store->hasIdentityConflict($paths, $data->page, $exceptPublicId)) {
            throw new FormConfigurationFailure('form_identity_conflict', 'Another configured form already uses this page and form marker. Choose the existing form or use a different marker.', ['form_marker' => 'This page and marker are already assigned to another form.']);
        }

        if ($data->recipient !== '' && filter_var($data->recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new FormConfigurationFailure('recipient_invalid', 'Enter one valid recipient email address.', ['recipient' => 'Enter one valid email address.']);
        }

        if ($data->subject !== '' && trim($data->subject) === '') {
            throw new FormConfigurationFailure('subject_invalid', 'Enter a subject or leave it blank until the form is ready to publish.', ['subject' => 'Enter a subject.']);
        }
    }

    private function validatePublication(PrivateStoragePaths $paths, FormConfigurationRecord $draft): void
    {
        $errors = [];

        if ($draft->recipient === '' || filter_var($draft->recipient, FILTER_VALIDATE_EMAIL) === false) {
            $errors['recipient'] = 'Enter one valid recipient email address before publishing.';
        }

        if (trim($draft->subject) === '') {
            $errors['subject'] = 'Enter a subject before publishing.';
        }

        if ($draft->fields === []) {
            $errors['fields_json'] = 'Add at least one mapped field before publishing. Unit 07 will normally provide these mappings.';
        }

        if ($errors !== []) {
            throw new FormConfigurationFailure('publication_incomplete', 'This form cannot be published yet. Complete each item identified below.', $errors);
        }

        $this->assertConfiguredHost($this->settingsStore->get($paths), $draft->page->host);
    }

    private function assertConfiguredHost(InstallationSettings $settings, string $host): void
    {
        $aliases = array_values(array_filter([$settings->bareDomain, $settings->wwwAlias], static fn (?string $alias): bool => $alias !== null && $alias !== ''));

        if (!in_array($host, $aliases, true)) {
            throw new FormConfigurationFailure('page_host_not_allowed', 'The page host must match one of the configured website aliases in Settings.', ['page_host' => 'Choose a configured website alias.']);
        }
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function audit(PrivateStoragePaths $paths, string $eventName, string $outcome): void
    {
        $this->store->recordAudit($paths, $eventName, $outcome, $this->clock->now());
    }
}
