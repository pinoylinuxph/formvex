<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationSummary;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormConfiguration\PublicFormResolution;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface FormConfigurationStore
{
    /** @return list<FormConfigurationSummary> */
    public function list(PrivateStoragePaths $paths, bool $includeTrash = false): array;

    public function find(PrivateStoragePaths $paths, string $publicId, bool $includeTrash = false): ?FormConfigurationDetails;

    public function hasIdentityConflict(PrivateStoragePaths $paths, PageIdentity $page, ?string $exceptPublicId = null): bool;

    public function createDraft(PrivateStoragePaths $paths, string $publicId, FormConfigurationDraftData $data, DateTimeImmutable $now): FormConfigurationDetails;

    public function updateDraft(PrivateStoragePaths $paths, string $publicId, int $expectedRevision, FormConfigurationDraftData $data, DateTimeImmutable $now): FormConfigurationDetails;

    public function publishDraft(PrivateStoragePaths $paths, string $publicId, int $expectedRevision, DateTimeImmutable $now): FormConfigurationRecord;

    public function trash(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    public function restore(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    public function hardDelete(PrivateStoragePaths $paths, string $publicId, DateTimeImmutable $now): void;

    public function resolveActive(PrivateStoragePaths $paths, PageIdentity $page): ?PublicFormResolution;

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $occurredAt): void;
}
