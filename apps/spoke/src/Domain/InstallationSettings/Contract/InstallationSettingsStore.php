<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestState;

interface InstallationSettingsStore
{
    public function get(PrivateStoragePaths $paths): InstallationSettings;

    public function save(PrivateStoragePaths $paths, InstallationSettings $settings, DateTimeImmutable $now): void;

    public function getTestState(PrivateStoragePaths $paths): SmtpTestState;

    public function saveTestState(PrivateStoragePaths $paths, SmtpTestState $state): void;

    public function recordAudit(
        PrivateStoragePaths $paths,
        string $eventName,
        string $outcome,
        DateTimeImmutable $occurredAt,
    ): void;
}
