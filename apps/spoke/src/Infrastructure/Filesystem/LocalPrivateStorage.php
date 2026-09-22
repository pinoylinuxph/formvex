<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\Contract\PrivateStorage;
use Formvex\Spoke\Domain\Installation\Contract\StorageLock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\InstallationMarker;
use Formvex\Spoke\Domain\Installation\PreflightCheck;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use JsonException;

final class LocalPrivateStorage implements PrivateStorage
{
    /** @var list<string> */
    private const PRIVATE_DIRECTORIES = [
        'database',
        'secrets',
        'logs',
        'exports',
        'diagnostics',
        'backups',
        'backups/scheduled',
        'backups/manual',
        'backups/temporary',
        'runtime',
    ];

    public function __construct(private readonly WebExposureVerifier $webExposureVerifier)
    {
    }

    public function check(InstallationConfiguration $configuration): array
    {
        try {
            $applicationRoot = $this->canonicalDirectory($configuration->applicationRoot, 'application_root');
            $webRoot = $this->canonicalDirectory($configuration->webRoot, 'web_root');
        } catch (InstallationFailure $failure) {
            return [PreflightCheck::fail($failure->failureCode, 'The configured filesystem path is invalid.')];
        }

        $checks = [PreflightCheck::pass('application_root', 'The Formvex application root is valid.')];
        $checks[] = is_writable($applicationRoot)
            ? PreflightCheck::pass('application_root_writable', 'The Formvex application root is writable.')
            : PreflightCheck::fail('application_root_unwritable', 'The Formvex application root is not writable.');

        $invalidDirectory = $this->findInvalidPrivateDirectory($applicationRoot);
        $checks[] = $invalidDirectory === null
            ? PreflightCheck::pass('private_directories', 'Private storage directories are available.')
            : PreflightCheck::fail($invalidDirectory, 'A private storage directory is invalid.');

        $checks[] = $this->hasOwnerOnlyExistingDirectories($applicationRoot)
            ? PreflightCheck::pass('private_permissions', 'Existing private directories have restrictive permissions.')
            : PreflightCheck::fail('private_permissions_invalid', 'Existing private directories are too broadly accessible.');

        if ($this->isWithin($applicationRoot, $webRoot)) {
            $checks[] = $this->verifyWebProtection($applicationRoot, $webRoot, $configuration->publicBaseUrl);
        } else {
            $checks[] = PreflightCheck::pass(
                'private_web_protection',
                'The application root is outside the configured public web root.',
            );
        }

        return $checks;
    }

    public function prepare(InstallationConfiguration $configuration): PrivateStoragePaths
    {
        $applicationRoot = $this->canonicalDirectory($configuration->applicationRoot, 'application_root');

        foreach (self::PRIVATE_DIRECTORIES as $directory) {
            $path = $applicationRoot . DIRECTORY_SEPARATOR . $directory;

            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                throw new InstallationFailure('private_directory_invalid');
            }

            if (!is_dir($path) && !mkdir($path, 0o700, true) && !is_dir($path)) {
                throw new InstallationFailure('private_storage_unwritable');
            }

            if (!chmod($path, 0o700)) {
                throw new InstallationFailure('private_permissions_invalid');
            }
        }

        return new PrivateStoragePaths(
            $applicationRoot,
            $applicationRoot . DIRECTORY_SEPARATOR . 'database',
            $applicationRoot . DIRECTORY_SEPARATOR . 'secrets',
            $applicationRoot . DIRECTORY_SEPARATOR . 'logs',
            $applicationRoot . DIRECTORY_SEPARATOR . 'exports',
            $applicationRoot . DIRECTORY_SEPARATOR . 'diagnostics',
            $applicationRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'scheduled',
            $applicationRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'manual',
            $applicationRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'temporary',
            $applicationRoot . DIRECTORY_SEPARATOR . 'runtime',
        );
    }

    public function acquireLock(PrivateStoragePaths $paths): StorageLock
    {
        $handle = fopen($paths->lockFile(), 'c+');

        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallationFailure('installation_in_progress');
        }

        chmod($paths->lockFile(), 0o600);

        return new LocalStorageLock($handle, $paths->lockFile());
    }

    public function readMarker(PrivateStoragePaths $paths): ?InstallationMarker
    {
        if (!is_file($paths->markerFile())) {
            return null;
        }

        $contents = file_get_contents($paths->markerFile());

        if ($contents === false) {
            throw new InstallationFailure('installation_state_invalid');
        }

        try {
            $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InstallationFailure('installation_state_invalid');
        }

        if (
            !is_array($data)
            || !isset($data['installation_id'], $data['schema_version'])
            || !is_string($data['installation_id'])
            || !is_string($data['schema_version'])
        ) {
            throw new InstallationFailure('installation_state_invalid');
        }

        return new InstallationMarker($data['installation_id'], $data['schema_version']);
    }

    public function writeMarker(PrivateStoragePaths $paths, InstallationMarker $marker): void
    {
        try {
            $contents = json_encode([
                'installation_id' => $marker->installationId,
                'schema_version' => $marker->schemaVersion,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InstallationFailure('installation_state_write_failed');
        }

        $temporaryPath = $paths->runtime . DIRECTORY_SEPARATOR . '.installation-state-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new InstallationFailure('installation_state_write_failed');
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new InstallationFailure('installation_state_write_failed');
            }

            chmod($temporaryPath, 0o600);
        } finally {
            fclose($handle);
        }

        if (!rename($temporaryPath, $paths->markerFile())) {
            @unlink($temporaryPath);
            throw new InstallationFailure('installation_state_write_failed');
        }
    }

    private function canonicalDirectory(string $path, string $failureCode): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            throw new InstallationFailure($failureCode . '_invalid');
        }

        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new InstallationFailure($failureCode . '_invalid');
        }

        if (is_link($path) || !is_dir($path)) {
            throw new InstallationFailure($failureCode . '_invalid');
        }

        $realPath = realpath($path);

        if ($realPath === false) {
            throw new InstallationFailure($failureCode . '_invalid');
        }

        return rtrim($realPath, DIRECTORY_SEPARATOR);
    }

    private function findInvalidPrivateDirectory(string $applicationRoot): ?string
    {
        foreach (self::PRIVATE_DIRECTORIES as $directory) {
            $path = $applicationRoot . DIRECTORY_SEPARATOR . $directory;

            if (is_link($path) || (file_exists($path) && !is_dir($path))) {
                return 'private_directory_invalid';
            }
        }

        return null;
    }

    private function hasOwnerOnlyExistingDirectories(string $applicationRoot): bool
    {
        foreach (self::PRIVATE_DIRECTORIES as $directory) {
            $path = $applicationRoot . DIRECTORY_SEPARATOR . $directory;

            if (!is_dir($path)) {
                continue;
            }

            $permissions = fileperms($path);

            if ($permissions === false || ($permissions & 0o077) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function verifyWebProtection(string $applicationRoot, string $webRoot, ?string $publicBaseUrl): PreflightCheck
    {
        if ($publicBaseUrl === null) {
            return PreflightCheck::fail(
                'private_web_protection_unverified',
                'A public HTTPS base URL is required to verify private storage under the web root.',
            );
        }

        $sentinelName = '.formvex-preflight-' . bin2hex(random_bytes(8)) . '.txt';
        $sentinelPath = $applicationRoot . DIRECTORY_SEPARATOR . $sentinelName;
        $sentinelUrl = $this->buildSentinelUrl($publicBaseUrl, $applicationRoot, $webRoot, $sentinelName);

        if (file_put_contents($sentinelPath, 'formvex-private-sentinel', LOCK_EX) === false) {
            return PreflightCheck::fail('private_web_sentinel_failed', 'The private web-protection check could not create its test file.');
        }

        chmod($sentinelPath, 0o600);
        $denied = $this->webExposureVerifier->isDenied($sentinelUrl);
        @unlink($sentinelPath);

        return $denied
            ? PreflightCheck::pass('private_web_protection', 'Private storage is denied by the web server.')
            : PreflightCheck::fail('private_web_protection_exposed', 'The web server did not deny private storage access.');
    }

    private function buildSentinelUrl(string $publicBaseUrl, string $applicationRoot, string $webRoot, string $sentinelName): string
    {
        $base = rtrim($publicBaseUrl, '/');
        $relative = trim(str_replace(DIRECTORY_SEPARATOR, '/', substr($applicationRoot, strlen($webRoot))), '/');
        $segments = $relative === '' ? [] : array_map('rawurlencode', explode('/', $relative));
        $segments[] = rawurlencode($sentinelName);

        return $base . '/' . implode('/', $segments);
    }

    private function isWithin(string $candidate, string $boundary): bool
    {
        $boundary = rtrim($boundary, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return $candidate === rtrim($boundary, DIRECTORY_SEPARATOR) || str_starts_with($candidate, $boundary);
    }
}
