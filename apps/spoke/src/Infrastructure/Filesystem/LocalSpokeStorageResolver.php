<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

final class LocalSpokeStorageResolver implements SpokeStorageResolver
{
    /**
     * @var list<string>
     */
    private const PRIVATE_DIRECTORIES = [
        'database',
        'secrets',
        'logs',
        'exports',
        'diagnostics',
        'backups/scheduled',
        'backups/manual',
        'backups/temporary',
        'runtime',
    ];

    public function resolve(string $applicationRoot): PrivateStoragePaths
    {
        if ($applicationRoot === '' || !str_starts_with($applicationRoot, DIRECTORY_SEPARATOR) || str_contains($applicationRoot, "\0") || str_contains($applicationRoot, '..')) {
            throw new AdministratorFailure('application_root_invalid');
        }

        $root = realpath($applicationRoot);

        if ($root === false || !is_dir($root) || is_link($applicationRoot)) {
            throw new AdministratorFailure('application_root_invalid');
        }

        foreach (self::PRIVATE_DIRECTORIES as $directory) {
            $path = $root . DIRECTORY_SEPARATOR . $directory;

            if (!is_dir($path) || is_link($path) || (fileperms($path) !== false && (fileperms($path) & 0o077) !== 0)) {
                throw new AdministratorFailure('private_storage_invalid');
            }
        }

        if (!is_file($root . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'installation-state.json')) {
            throw new AdministratorFailure('installation_required');
        }

        if (!is_file($root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'formvex.sqlite')) {
            throw new AdministratorFailure('installation_required');
        }

        return new PrivateStoragePaths(
            $root,
            $root . DIRECTORY_SEPARATOR . 'database',
            $root . DIRECTORY_SEPARATOR . 'secrets',
            $root . DIRECTORY_SEPARATOR . 'logs',
            $root . DIRECTORY_SEPARATOR . 'exports',
            $root . DIRECTORY_SEPARATOR . 'diagnostics',
            $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'scheduled',
            $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'manual',
            $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'temporary',
            $root . DIRECTORY_SEPARATOR . 'runtime',
        );
    }

    public function assertOperatorOwns(PrivateStoragePaths $paths): void
    {
        $owner = fileowner($paths->applicationRoot);

        if ($owner === false) {
            throw new AdministratorFailure('recovery_authorization_failed');
        }

        if (function_exists('posix_geteuid')) {
            $effectiveUser = posix_geteuid();

            if ($effectiveUser === 0 || $effectiveUser !== $owner) {
                throw new AdministratorFailure('recovery_authorization_failed');
            }

            return;
        }

        if (!function_exists('getmyuid') || getmyuid() !== $owner) {
            throw new AdministratorFailure('recovery_authorization_failed');
        }
    }
}
