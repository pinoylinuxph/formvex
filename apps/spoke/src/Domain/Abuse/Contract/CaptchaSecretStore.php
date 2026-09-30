<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface CaptchaSecretStore
{
    public function isConfigured(PrivateStoragePaths $paths, string $slot): bool;

    public function write(PrivateStoragePaths $paths, string $slot, string $secret): void;

    public function read(PrivateStoragePaths $paths, string $slot): string;

    public function remove(PrivateStoragePaths $paths, string $slot): void;
}
