<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface SmtpSecretStore
{
    public function isConfigured(PrivateStoragePaths $paths, string $slot): bool;

    public function write(PrivateStoragePaths $paths, string $slot, string $password): void;

    public function read(PrivateStoragePaths $paths, string $slot): string;

    public function remove(PrivateStoragePaths $paths, string $slot): void;
}
