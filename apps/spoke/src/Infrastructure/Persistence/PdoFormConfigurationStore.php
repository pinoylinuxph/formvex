<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservationFingerprint;
use Formvex\Spoke\Domain\FormConfiguration\Contract\FormConfigurationStore;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationState;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationSummary;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldChoice;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormConfiguration\PublicFormResolution;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use PDOStatement;
use Throwable;
use ValueError;

final class PdoFormConfigurationStore implements FormConfigurationStore
{
    public function list(PrivateStoragePaths $paths, bool $includeTrash = false): array
    {
        $connection = $this->connection($paths);
        $sql = 'SELECT public_id, display_name, deleted_at, updated_at FROM form_configurations';

        if (!$includeTrash) {
            $sql .= ' WHERE deleted_at IS NULL';
        }

        $sql .= ' ORDER BY updated_at DESC, id DESC';
        $statement = $connection->query($sql);
        $rows = $statement === false ? [] : $this->rows($statement);
        $summaries = [];

        foreach ($rows as $row) {
            if (!is_string($row['public_id'] ?? null) || !is_string($row['display_name'] ?? null) || !is_string($row['updated_at'] ?? null)) {
                throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid form configuration record.');
            }

            $draft = $this->draftRow($connection, $row['public_id']);
            $published = $this->latestPublishedRow($connection, $row['public_id']);
            $publishedState = null;
            $publishedVersion = 0;

            if ($published !== null) {
                try {
                    $publishedState = FormConfigurationState::from($this->stringValue($published, 'state'));
                } catch (ValueError) {
                    throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid form lifecycle state.');
                }
                $publishedVersion = $this->integerValue($published, 'version_number');
            }

            $summaries[] = new FormConfigurationSummary(
                $row['public_id'],
                $row['display_name'],
                $draft === null ? 0 : $this->integerValue($draft, 'revision'),
                $publishedVersion,
                $publishedState,
                $row['deleted_at'] !== null,
                $this->timestamp($row['updated_at']),
            );
        }

        return $summaries;
    }

    public function find(PrivateStoragePaths $paths, string $publicId, bool $includeTrash = false): ?FormConfigurationDetails
    {
        $connection = $this->connection($paths);
        $sql = 'SELECT id, public_id, display_name, deleted_at FROM form_configurations WHERE public_id = :public_id';

        if (!$includeTrash) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $statement = $connection->prepare($sql);
        $statement->execute(['public_id' => $publicId]);
        $form = $this->fetchRow($statement);

        if ($form === null) {
            return null;
        }

        $draft = $this->draftRow($connection, $publicId);

        if ($draft === null) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex could not find the editable draft for this form.');
        }

        $versionsStatement = $connection->prepare(
            "SELECT v.*, f.public_id, f.display_name, p.host, p.path, p.form_marker
             FROM form_configuration_versions v
             INNER JOIN form_configurations f ON f.id = v.form_id
             INNER JOIN form_configuration_version_pages p ON p.version_id = v.id
             WHERE f.public_id = :public_id AND v.state <> 'draft'
             ORDER BY v.version_number DESC",
        );
        $versionsStatement->execute(['public_id' => $publicId]);
        $versionRows = $this->rows($versionsStatement);
        $publishedVersions = [];

        foreach ($versionRows as $versionRow) {
            $publishedVersions[] = $this->hydrateRecord($connection, $versionRow);
        }

        return new FormConfigurationDetails(
            $this->hydrateRecord($connection, array_merge($draft, [
                'public_id' => $form['public_id'],
                'display_name' => $form['display_name'],
                'deleted_at' => $form['deleted_at'],
            ])),
            $publishedVersions,
            $form['deleted_at'] !== null,
        );
    }

    public function hasIdentityConflict(PrivateStoragePaths $paths, PageIdentity $page, ?string $exceptPublicId = null): bool
    {
        $connection = $this->connection($paths);
        $sql = "SELECT 1
                FROM form_configuration_version_pages p
                INNER JOIN form_configuration_versions v ON v.id = p.version_id
                INNER JOIN form_configurations f ON f.id = v.form_id
                WHERE f.deleted_at IS NULL
                  AND p.host = :host
                  AND p.path = :path
                  AND p.form_marker = :form_marker";
        $parameters = [
            'host' => $page->host,
            'path' => $page->path,
            'form_marker' => $page->formMarker,
        ];

        if ($exceptPublicId !== null) {
            $sql .= ' AND f.public_id <> :except_public_id';
            $parameters['except_public_id'] = $exceptPublicId;
        }

        $statement = $connection->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function createDraft(PrivateStoragePaths $paths, string $publicId, FormConfigurationDraftData $data, DateTimeImmutable $now): FormConfigurationDetails
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $timestamp = $this->formatTimestamp($now);
            $form = $connection->prepare(
                'INSERT INTO form_configurations (public_id, display_name, created_at, updated_at) VALUES (:public_id, :display_name, :created_at, :updated_at)',
            );
            $form->execute([
                'public_id' => $publicId,
                'display_name' => $data->displayName,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $formId = $this->lastInsertId($connection);
            $versionId = $this->insertVersion($connection, $formId, 0, FormConfigurationState::DRAFT, 1, $data, $timestamp, null);
            $this->insertPageAndFields($connection, $versionId, $data);
            $this->insertEvidence($connection, $versionId, $timestamp);
            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('form_save_failed', 'Formvex could not create the form draft. The form was not saved.');
        }

        $details = $this->find($paths, $publicId);

        if ($details === null) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex created the form but could not reload its draft.');
        }

        return $details;
    }

    public function updateDraft(PrivateStoragePaths $paths, string $publicId, int $expectedRevision, FormConfigurationDraftData $data, DateTimeImmutable $now): FormConfigurationDetails
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $form = $this->formRow($connection, $publicId, true);

            if ($form === null) {
                throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the form draft to update.');
            }

            $draft = $this->draftRow($connection, $publicId);

            if ($draft === null) {
                throw new FormConfigurationFailure('form_state_invalid', 'Formvex could not find the editable draft for this form.');
            }

            $revision = $this->integerValue($draft, 'revision');

            if ($revision !== $expectedRevision) {
                throw new FormConfigurationFailure('draft_conflict', 'This form changed after you opened it. Reload the latest draft before saving your changes.');
            }

            $timestamp = $this->formatTimestamp($now);
            $updateForm = $connection->prepare('UPDATE form_configurations SET display_name = :display_name, updated_at = :updated_at WHERE id = :id');
            $updateForm->execute(['display_name' => $data->displayName, 'updated_at' => $timestamp, 'id' => $this->integerValue($form, 'id')]);
            $updateVersion = $connection->prepare(
                'UPDATE form_configuration_versions SET revision = revision + 1, recipient = :recipient, subject = :subject, captcha_enabled = :captcha_enabled, captcha_site_key = :captcha_site_key, evidence_revision = evidence_revision + 1, updated_at = :updated_at WHERE id = :id',
            );
            $updateVersion->execute([
                'recipient' => $data->recipient,
                'subject' => $data->subject,
                'captcha_enabled' => $data->captchaEnabled ? 1 : 0,
                'captcha_site_key' => $data->captchaSiteKey,
                'updated_at' => $timestamp,
                'id' => $this->integerValue($draft, 'id'),
            ]);
            $this->replacePageAndFields($connection, $this->integerValue($draft, 'id'), $data);
            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('form_save_failed', 'Formvex could not update the form draft. The previous draft remains active.');
        }

        $details = $this->find($paths, $publicId);

        if ($details === null) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex updated the form but could not reload its draft.');
        }

        return $details;
    }

    public function publishDraft(PrivateStoragePaths $paths, string $publicId, int $expectedRevision, DateTimeImmutable $now): FormConfigurationRecord
    {
        $connection = $this->connection($paths);
        $publishedVersionId = 0;

        try {
            $connection->beginTransaction();
            $form = $this->formRow($connection, $publicId, false);
            $draft = $this->draftRow($connection, $publicId);

            if ($form === null || $draft === null) {
                throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the form draft to publish.');
            }

            if ($this->integerValue($draft, 'revision') !== $expectedRevision) {
                throw new FormConfigurationFailure('draft_conflict', 'This form changed after you opened it. Reload the latest draft before publishing it.');
            }

            $versionStatement = $connection->prepare('SELECT COALESCE(MAX(version_number), 0) FROM form_configuration_versions WHERE form_id = :form_id AND state <> :draft_state');
            $versionStatement->execute(['form_id' => $this->integerValue($form, 'id'), 'draft_state' => FormConfigurationState::DRAFT->value]);
            $versionNumber = $this->fetchInteger($versionStatement) + 1;
            $timestamp = $this->formatTimestamp($now);
            $publishedVersionId = $this->insertVersionFromDraft($connection, $form, $draft, $versionNumber, $timestamp);
            $this->copyPageAndFields($connection, $this->integerValue($draft, 'id'), $publishedVersionId);
            $this->insertEvidence($connection, $publishedVersionId, $timestamp);
            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('publication_failed', 'Formvex could not publish the form configuration. No new published version was created.');
        }

        return $this->recordByVersionId($connection, $publishedVersionId);
    }

    public function trash(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $this->setDeletedAt($paths, $publicId, $this->formatTimestamp($now));
    }

    public function restore(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $this->setDeletedAt($paths, $publicId, null, $now);
    }

    public function hardDelete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $form = $this->formRow($connection, $publicId, true);

            if ($form === null || $form['deleted_at'] === null) {
                throw new FormConfigurationFailure('hard_delete_requires_trash', 'Move the form to Trash before permanently deleting it.');
            }

            if ($this->hasProtectedReferences($connection, $this->integerValue($form, 'id'))) {
                throw new FormConfigurationFailure('hard_delete_dependencies', 'Formvex cannot permanently delete this form because protected submission, delivery, audit, or configuration records still reference it.');
            }

            $statement = $connection->prepare('DELETE FROM form_configurations WHERE id = :id');
            $statement->execute(['id' => $this->integerValue($form, 'id')]);

            if ($statement->rowCount() !== 1) {
                throw new FormConfigurationFailure('hard_delete_failed', 'Formvex could not permanently delete the form.');
            }

            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('hard_delete_failed', 'Formvex could not permanently delete the form. No records were removed.');
        }
    }

    public function resolveActive(PrivateStoragePaths $paths, PageIdentity $page): ?PublicFormResolution
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            "SELECT f.public_id, v.id, v.version_number, p.form_marker, v.captcha_enabled, v.captcha_site_key
             FROM form_configuration_version_pages p
             INNER JOIN form_configuration_versions v ON v.id = p.version_id AND v.state = 'active'
             INNER JOIN form_configurations f ON f.id = v.form_id AND f.deleted_at IS NULL
             WHERE p.host = :host AND p.path = :path AND p.form_marker = :form_marker
             LIMIT 1",
        );
        $statement->execute([
            'host' => $page->host,
            'path' => $page->path,
            'form_marker' => $page->formMarker,
        ]);
        $row = $this->fetchRow($statement);

        if ($row === null || !is_string($row['public_id'] ?? null) || !is_string($row['form_marker'] ?? null)) {
            return null;
        }

        return new PublicFormResolution(
            $row['public_id'],
            $this->integerValue($row, 'version_number'),
            $row['form_marker'],
            $this->integerValue($row, 'captcha_enabled') === 1,
            'turnstile',
            $this->stringValue($row, 'captcha_site_key'),
            FormChangeObservationFingerprint::fromFields($this->fields($connection, $this->integerValue($row, 'id'))),
        );
    }

    public function findActive(PrivateStoragePaths $paths, string $publicId): ?FormConfigurationRecord
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            "SELECT v.*, f.public_id, f.display_name, p.host, p.path, p.form_marker
             FROM form_configuration_versions v
             INNER JOIN form_configurations f ON f.id = v.form_id
             INNER JOIN form_configuration_version_pages p ON p.version_id = v.id
             WHERE f.public_id = :public_id AND f.deleted_at IS NULL AND v.state = 'active'
             LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $this->fetchRow($statement);

        return $row === null ? null : $this->hydrateRecord($connection, $row);
    }

    public function findPublished(PrivateStoragePaths $paths, string $publicId, int $versionNumber): ?FormConfigurationRecord
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            "SELECT v.*, f.public_id, f.display_name, p.host, p.path, p.form_marker
             FROM form_configuration_versions v
             INNER JOIN form_configurations f ON f.id = v.form_id
             INNER JOIN form_configuration_version_pages p ON p.version_id = v.id
             WHERE f.public_id = :public_id AND f.deleted_at IS NULL AND v.version_number = :version_number AND v.state = 'published'
             LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId, 'version_number' => $versionNumber]);
        $row = $this->fetchRow($statement);

        return $row === null ? null : $this->hydrateRecord($connection, $row);
    }

    public function activatePublished(PrivateStoragePaths $paths, string $publicId, int $versionNumber, DateTimeImmutable $now): FormConfigurationRecord
    {
        $connection = $this->connection($paths);
        $versionId = 0;

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $form = $this->formRow($connection, $publicId, false);

            if ($form === null) {
                throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the form to activate.');
            }

            $targetStatement = $connection->prepare(
                "SELECT id FROM form_configuration_versions WHERE form_id = :form_id AND version_number = :version_number AND state = 'published' LIMIT 1",
            );
            $targetStatement->execute([
                'form_id' => $this->integerValue($form, 'id'),
                'version_number' => $versionNumber,
            ]);
            $target = $targetStatement->fetchColumn();

            if ((!is_int($target) && !is_string($target)) || (is_string($target) && !ctype_digit($target)) || (int) $target < 1) {
                throw new FormConfigurationFailure('published_version_unavailable', 'The selected published version is no longer available. Reload the form and qualify the current version again.');
            }

            $versionId = (int) $target;
            $timestamp = $this->formatTimestamp($now);
            $disable = $connection->prepare("UPDATE form_configuration_versions SET state = 'disabled', updated_at = :updated_at WHERE form_id = :form_id AND state = 'active'");
            $disable->execute([
                'updated_at' => $timestamp,
                'form_id' => $this->integerValue($form, 'id'),
            ]);
            $activate = $connection->prepare("UPDATE form_configuration_versions SET state = 'active', updated_at = :updated_at WHERE id = :id AND state = 'published'");
            $activate->execute(['updated_at' => $timestamp, 'id' => $versionId]);

            if ($activate->rowCount() !== 1) {
                throw new FormConfigurationFailure('activation_conflict', 'The form changed before activation completed. Reload the form and qualify the current published version again.');
            }

            $formUpdate = $connection->prepare('UPDATE form_configurations SET updated_at = :updated_at WHERE id = :id');
            $formUpdate->execute(['updated_at' => $timestamp, 'id' => $this->integerValue($form, 'id')]);
            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('activation_failed', 'Formvex could not activate the published form. The previous active state remains in place.');
        }

        return $this->recordByVersionId($connection, $versionId);
    }

    public function disableActive(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $form = $this->formRow($connection, $publicId, false);

            if ($form === null) {
                throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the form to disable.');
            }

            $timestamp = $this->formatTimestamp($now);
            $disable = $connection->prepare("UPDATE form_configuration_versions SET state = 'disabled', updated_at = :updated_at WHERE form_id = :form_id AND state = 'active'");
            $disable->execute([
                'updated_at' => $timestamp,
                'form_id' => $this->integerValue($form, 'id'),
            ]);
            $formUpdate = $connection->prepare('UPDATE form_configurations SET updated_at = :updated_at WHERE id = :id');
            $formUpdate->execute(['updated_at' => $timestamp, 'id' => $this->integerValue($form, 'id')]);
            $connection->commit();
        } catch (FormConfigurationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormConfigurationFailure('disable_failed', 'Formvex could not disable the form. Its previous state remains in place.');
        }
    }

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at) VALUES (:event_name, :outcome, :occurred_at)');
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->formatTimestamp($occurredAt),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function formRow(PDO $connection, string $publicId, bool $includeTrash): ?array
    {
        $sql = 'SELECT id, public_id, display_name, deleted_at FROM form_configurations WHERE public_id = :public_id';

        if (!$includeTrash) {
            $sql .= ' AND deleted_at IS NULL';
        }

        $statement = $connection->prepare($sql);
        $statement->execute(['public_id' => $publicId]);
        return $this->fetchRow($statement);
    }

    /** @return array<string, mixed>|null */
    private function draftRow(PDO $connection, string $publicId): ?array
    {
        $statement = $connection->prepare(
            "SELECT v.*, f.public_id, f.display_name, p.host, p.path, p.form_marker
             FROM form_configuration_versions v
             INNER JOIN form_configurations f ON f.id = v.form_id
             INNER JOIN form_configuration_version_pages p ON p.version_id = v.id
             WHERE f.public_id = :public_id AND v.state = 'draft'
             LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId]);
        return $this->fetchRow($statement);
    }

    /** @return array<string, mixed>|null */
    private function latestPublishedRow(PDO $connection, string $publicId): ?array
    {
        $statement = $connection->prepare(
            "SELECT v.*
             FROM form_configuration_versions v
             INNER JOIN form_configurations f ON f.id = v.form_id
             WHERE f.public_id = :public_id AND v.state <> 'draft'
             ORDER BY v.version_number DESC LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId]);
        return $this->fetchRow($statement);
    }

    private function insertVersion(PDO $connection, int $formId, int $versionNumber, FormConfigurationState $state, int $revision, FormConfigurationDraftData $data, string $timestamp, ?string $publishedAt): int
    {
        $statement = $connection->prepare(
            'INSERT INTO form_configuration_versions (form_id, version_number, state, revision, recipient, subject, captcha_enabled, captcha_site_key, evidence_revision, published_at, created_at, updated_at) '
            . 'VALUES (:form_id, :version_number, :state, :revision, :recipient, :subject, :captcha_enabled, :captcha_site_key, 1, :published_at, :created_at, :updated_at)',
        );
        $statement->execute([
            'form_id' => $formId,
            'version_number' => $versionNumber,
            'state' => $state->value,
            'revision' => $revision,
            'recipient' => $data->recipient,
            'subject' => $data->subject,
            'captcha_enabled' => $data->captchaEnabled ? 1 : 0,
            'captcha_site_key' => $data->captchaSiteKey,
            'published_at' => $publishedAt,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->lastInsertId($connection);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, mixed> $draft
     */
    private function insertVersionFromDraft(PDO $connection, array $form, array $draft, int $versionNumber, string $timestamp): int
    {
        $statement = $connection->prepare(
            'INSERT INTO form_configuration_versions (form_id, version_number, state, revision, recipient, subject, captcha_enabled, captcha_site_key, evidence_revision, published_at, created_at, updated_at) '
            . "VALUES (:form_id, :version_number, 'published', 1, :recipient, :subject, :captcha_enabled, :captcha_site_key, 1, :published_at, :created_at, :updated_at)",
        );
        $statement->execute([
            'form_id' => $this->integerValue($form, 'id'),
            'version_number' => $versionNumber,
            'recipient' => $this->stringValue($draft, 'recipient'),
            'subject' => $this->stringValue($draft, 'subject'),
            'captcha_enabled' => $this->integerValue($draft, 'captcha_enabled'),
            'captcha_site_key' => $this->stringValue($draft, 'captcha_site_key'),
            'published_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $this->lastInsertId($connection);
    }

    private function insertPageAndFields(PDO $connection, int $versionId, FormConfigurationDraftData $data): void
    {
        $page = $connection->prepare('INSERT INTO form_configuration_version_pages (version_id, host, path, form_marker) VALUES (:version_id, :host, :path, :form_marker)');
        $page->execute([
            'version_id' => $versionId,
            'host' => $data->page->host,
            'path' => $data->page->path,
            'form_marker' => $data->page->formMarker,
        ]);
        $this->insertFields($connection, $versionId, $data->fields);
    }

    private function replacePageAndFields(PDO $connection, int $versionId, FormConfigurationDraftData $data): void
    {
        $page = $connection->prepare('UPDATE form_configuration_version_pages SET host = :host, path = :path, form_marker = :form_marker WHERE version_id = :version_id');
        $page->execute([
            'version_id' => $versionId,
            'host' => $data->page->host,
            'path' => $data->page->path,
            'form_marker' => $data->page->formMarker,
        ]);
        $connection->prepare('DELETE FROM form_configuration_version_fields WHERE version_id = :version_id')->execute(['version_id' => $versionId]);
        $this->insertFields($connection, $versionId, $data->fields);
    }

    /** @param list<FormFieldDefinition> $fields */
    private function insertFields(PDO $connection, int $versionId, array $fields): void
    {
        foreach ($fields as $field) {
            $statement = $connection->prepare(
                'INSERT INTO form_configuration_version_fields (version_id, field_key, control_name, control_type, display_label, parameter_key, ordinal, is_required, max_length) '
                . 'VALUES (:version_id, :field_key, :control_name, :control_type, :display_label, :parameter_key, :ordinal, :is_required, :max_length)',
            );
            $statement->execute([
                'version_id' => $versionId,
                'field_key' => $field->fieldKey,
                'control_name' => $field->controlName,
                'control_type' => $field->controlType,
                'display_label' => $field->displayLabel,
                'parameter_key' => $field->parameterKey,
                'ordinal' => $field->ordinal,
                'is_required' => $field->required ? 1 : 0,
                'max_length' => $field->maxLength,
            ]);
            $fieldId = $this->lastInsertId($connection);

            foreach ($field->choices as $choice) {
                $choiceStatement = $connection->prepare('INSERT INTO form_configuration_field_choices (field_id, choice_value, choice_label) VALUES (:field_id, :choice_value, :choice_label)');
                $choiceStatement->execute([
                    'field_id' => $fieldId,
                    'choice_value' => $choice->value,
                    'choice_label' => $choice->label,
                ]);
            }
        }
    }

    private function copyPageAndFields(PDO $connection, int $sourceVersionId, int $targetVersionId): void
    {
        $page = $connection->prepare('INSERT INTO form_configuration_version_pages (version_id, host, path, form_marker) SELECT :target_id, host, path, form_marker FROM form_configuration_version_pages WHERE version_id = :source_id');
        $page->execute(['target_id' => $targetVersionId, 'source_id' => $sourceVersionId]);
        $fieldsStatement = $connection->prepare('SELECT * FROM form_configuration_version_fields WHERE version_id = :version_id ORDER BY ordinal');
        $fieldsStatement->execute(['version_id' => $sourceVersionId]);

        foreach ($this->rows($fieldsStatement) as $field) {
            $insert = $connection->prepare(
                'INSERT INTO form_configuration_version_fields (version_id, field_key, control_name, control_type, display_label, parameter_key, ordinal, is_required, max_length) '
                . 'VALUES (:version_id, :field_key, :control_name, :control_type, :display_label, :parameter_key, :ordinal, :is_required, :max_length)',
            );
            $insert->execute([
                'version_id' => $targetVersionId,
                'field_key' => $field['field_key'],
                'control_name' => $field['control_name'],
                'control_type' => $field['control_type'],
                'display_label' => $field['display_label'],
                'parameter_key' => $field['parameter_key'],
                'ordinal' => $field['ordinal'],
                'is_required' => $field['is_required'],
                'max_length' => $field['max_length'],
            ]);
            $targetFieldId = $this->lastInsertId($connection);
            $choices = $connection->prepare('SELECT choice_value, choice_label FROM form_configuration_field_choices WHERE field_id = :field_id');
            $choices->execute(['field_id' => $this->integerValue($field, 'id')]);

            foreach ($this->rows($choices) as $choice) {
                $choiceInsert = $connection->prepare('INSERT INTO form_configuration_field_choices (field_id, choice_value, choice_label) VALUES (:field_id, :choice_value, :choice_label)');
                $choiceInsert->execute([
                    'field_id' => $targetFieldId,
                    'choice_value' => $choice['choice_value'],
                    'choice_label' => $choice['choice_label'],
                ]);
            }
        }
    }

    private function insertEvidence(PDO $connection, int $versionId, string $timestamp): void
    {
        $statement = $connection->prepare('INSERT INTO form_configuration_evidence (version_id, updated_at) VALUES (:version_id, :updated_at)');
        $statement->execute(['version_id' => $versionId, 'updated_at' => $timestamp]);
    }

    private function recordByVersionId(PDO $connection, int $versionId): FormConfigurationRecord
    {
        $statement = $connection->prepare(
            'SELECT v.*, f.public_id, f.display_name, p.host, p.path, p.form_marker '
            . 'FROM form_configuration_versions v INNER JOIN form_configurations f ON f.id = v.form_id '
            . 'INNER JOIN form_configuration_version_pages p ON p.version_id = v.id WHERE v.id = :id',
        );
        $statement->execute(['id' => $versionId]);
        $row = $this->fetchRow($statement);

        if ($row === null) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex could not reload the published form configuration.');
        }

        return $this->hydrateRecord($connection, $row);
    }

    /** @param array<string, mixed> $row */
    private function hydrateRecord(PDO $connection, array $row): FormConfigurationRecord
    {
        try {
            $state = FormConfigurationState::from($this->stringValue($row, 'state'));
            $publicId = $this->stringValue($row, 'public_id');
            $displayName = $this->stringValue($row, 'display_name');
            $page = PageIdentity::fromInput(
                $this->stringValue($row, 'host'),
                $this->stringValue($row, 'path'),
                $this->stringValue($row, 'form_marker'),
            );
        } catch (ValueError|FormConfigurationFailure $failure) {
            if ($failure instanceof FormConfigurationFailure) {
                throw new FormConfigurationFailure('form_state_invalid', 'Formvex found invalid saved form configuration data.');
            }

            throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid saved form lifecycle state.');
        }

        return new FormConfigurationRecord(
            $publicId,
            $displayName,
            $this->integerValue($row, 'version_number'),
            $this->integerValue($row, 'revision'),
            $state,
            $page,
            $this->stringValue($row, 'recipient'),
            $this->stringValue($row, 'subject'),
            $this->fields($connection, $this->integerValue($row, 'id')),
            $this->integerValue($row, 'evidence_revision'),
            $this->timestamp($this->stringValue($row, 'created_at')),
            $this->timestamp($this->stringValue($row, 'updated_at')),
            $row['published_at'] === null ? null : $this->timestamp($this->stringValue($row, 'published_at')),
            $this->integerValue($row, 'captcha_enabled') === 1,
            $this->stringValue($row, 'captcha_site_key'),
            $this->integerValue($row, 'id'),
        );
    }

    /** @return list<FormFieldDefinition> */
    private function fields(PDO $connection, int $versionId): array
    {
        $statement = $connection->prepare('SELECT * FROM form_configuration_version_fields WHERE version_id = :version_id ORDER BY ordinal');
        $statement->execute(['version_id' => $versionId]);
        $fields = [];

        foreach ($this->rows($statement) as $row) {
            $choiceStatement = $connection->prepare('SELECT choice_value, choice_label FROM form_configuration_field_choices WHERE field_id = :field_id ORDER BY id');
            $choiceStatement->execute(['field_id' => $this->integerValue($row, 'id')]);
            $choices = [];

            foreach ($this->rows($choiceStatement) as $choice) {
                if (is_string($choice['choice_value'] ?? null) && is_string($choice['choice_label'] ?? null)) {
                    $choices[] = new FormFieldChoice($choice['choice_value'], $choice['choice_label']);
                }
            }

            $fields[] = new FormFieldDefinition(
                $this->stringValue($row, 'field_key'),
                $this->stringValue($row, 'control_name'),
                $this->stringValue($row, 'control_type'),
                $this->stringValue($row, 'display_label'),
                $this->stringValue($row, 'parameter_key'),
                $this->integerValue($row, 'ordinal'),
                $this->integerValue($row, 'is_required') === 1,
                $this->integerValue($row, 'max_length'),
                $choices,
            );
        }

        FormFieldDefinition::validateList($fields);

        return $fields;
    }

    private function setDeletedAt(PrivateStoragePaths $paths, string $publicId, ?string $deletedAt, ?DateTimeImmutable $now = null): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare('UPDATE form_configurations SET deleted_at = :deleted_at, updated_at = :updated_at WHERE public_id = :public_id');
        $statement->execute([
            'deleted_at' => $deletedAt,
            'updated_at' => $this->formatTimestamp($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'public_id' => $publicId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new FormConfigurationFailure('form_not_found', 'Formvex could not find the form to update.');
        }
    }

    private function hasProtectedReferences(PDO $connection, int $formId): bool
    {
        foreach (['submissions', 'delivery_jobs', 'delivery_attempts', 'form_audit_events'] as $table) {
            $tableCheck = $connection->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name");
            $tableCheck->execute(['table_name' => $table]);

            if ($tableCheck->fetchColumn() === false) {
                continue;
            }

            $columnCheck = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name IN (\'form_id\', \'form_configuration_id\') LIMIT 1');
            $columnCheck->execute(['table_name' => $table]);

            if ($columnCheck->fetchColumn() !== false) {
                $column = $this->referenceColumn($connection, $table);
                $reference = $connection->query('SELECT COUNT(*) FROM ' . $this->quotedIdentifier($table) . ' WHERE ' . $this->quotedIdentifier($column) . ' = ' . $formId);

                if ($reference !== false && $this->fetchInteger($reference) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function referenceColumn(PDO $connection, string $table): string
    {
        $statement = $connection->query('PRAGMA table_info(' . $this->quotedIdentifier($table) . ')');

        foreach ($statement === false ? [] : $this->rows($statement) as $column) {
            if (in_array($column['name'] ?? null, ['form_id', 'form_configuration_id'], true)) {
                return (string) $column['name'];
            }
        }

        return 'form_id';
    }

    private function quotedIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** @return list<array<string, mixed>> */
    private function rows(PDOStatement $statement): array
    {
        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = [];

            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function fetchRow(PDOStatement $statement): ?array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function lastInsertId(PDO $connection): int
    {
        $value = $connection->lastInsertId();

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex could not identify a saved form configuration record.');
        }

        return (int) $value;
    }

    private function fetchInteger(PDOStatement $statement): int
    {
        $value = $statement->fetchColumn();

        if (!is_int($value) && !is_string($value)) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid saved numeric value.');
        }

        return (int) $value;
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new FormConfigurationFailure('installation_required', 'Formvex is not initialized. Run the installation command before managing forms.');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');

            return $connection;
        } catch (Throwable) {
            throw new FormConfigurationFailure('database_unavailable', 'Formvex could not access its local database. Check private storage and try again.');
        }
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid saved form configuration value.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integerValue(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !is_string($row[$key] ?? null) && !is_float($row[$key] ?? null)) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid saved form configuration number.');
        }

        return (int) $row[$key];
    }

    private function timestamp(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new FormConfigurationFailure('form_state_invalid', 'Formvex found an invalid saved form configuration timestamp.');
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
