<?php

declare(strict_types=1);

namespace Formvex\Core\Release;

use JsonException;
use ZipArchive;

final class ReleasePackageVerifier
{
    private const MANIFEST = 'RELEASE-MANIFEST.json';

    /**
     * @var list<string>
     */
    private const REQUIRED_ENTRIES = [
        'apps/spoke/public/index.php',
        'build/client.js',
        'build/spoke-admin.js',
        'composer.lock',
        'vendor/autoload.php',
        'LICENSE',
        'NOTICE',
        self::MANIFEST,
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_PREFIXES = [
        '.git/',
        'apps/hub/',
        'backups/',
        'docs/context/',
        'logoslab/',
        'node_modules/',
        'test-results/',
        'tests/',
        'var/',
    ];

    public function verify(string $archivePath, ?string $expectedSha256 = null): ReleaseVerificationResult
    {
        if (!is_file($archivePath) || is_link($archivePath)) {
            throw new ReleaseVerificationFailure('package_missing', 'The release package could not be read.');
        }

        if ($expectedSha256 !== null && (!preg_match('/\A[a-f0-9]{64}\z/i', $expectedSha256) || !hash_equals(strtolower($expectedSha256), hash_file('sha256', $archivePath) ?: ''))) {
            throw new ReleaseVerificationFailure('package_digest_invalid', 'The release package SHA-256 digest does not match the expected value.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new ReleaseVerificationFailure('package_invalid', 'The release package is not a readable ZIP archive.');
        }

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $name === '' || isset($entries[$name])) {
                    throw new ReleaseVerificationFailure('package_entry_invalid', 'The release package contains an invalid or duplicate entry.');
                }
                $this->assertSafeEntry($name);

                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $opsys, $attributes, ZipArchive::OPSYS_UNIX) && is_int($attributes) && (($attributes >> 16) & 0xF000) === 0xA000) {
                    throw new ReleaseVerificationFailure('package_symlink_forbidden', 'The release package contains a symlink.');
                }
                $entries[$name] = true;
            }

            foreach (self::REQUIRED_ENTRIES as $required) {
                if (!isset($entries[$required])) {
                    throw new ReleaseVerificationFailure('package_entry_missing', 'The release package is missing a required entry.');
                }
            }

            $manifestRaw = $zip->getFromName(self::MANIFEST);
            if (!is_string($manifestRaw)) {
                throw new ReleaseVerificationFailure('manifest_missing', 'The release manifest could not be read.');
            }
            try {
                $manifest = json_decode($manifestRaw, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $failure) {
                throw new ReleaseVerificationFailure('manifest_invalid', 'The release manifest is not valid JSON.');
            }
            if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1 || !is_string($manifest['release_version'] ?? null) || !is_array($manifest['files'] ?? null)) {
                throw new ReleaseVerificationFailure('manifest_invalid', 'The release manifest has an unsupported structure.');
            }

            $manifestEntries = [];
            foreach ($manifest['files'] as $file) {
                if (!is_array($file) || !is_string($file['path'] ?? null) || !is_string($file['sha256'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/i', $file['sha256'])) {
                    throw new ReleaseVerificationFailure('manifest_entry_invalid', 'The release manifest contains an invalid file checksum entry.');
                }
                $path = $file['path'];
                $this->assertSafeEntry($path);
                if ($path === self::MANIFEST || isset($manifestEntries[$path]) || !isset($entries[$path])) {
                    throw new ReleaseVerificationFailure('manifest_entry_invalid', 'The release manifest does not match the package entries.');
                }
                $contents = $zip->getFromName($path);
                if (!is_string($contents) || !hash_equals(strtolower($file['sha256']), hash('sha256', $contents))) {
                    throw new ReleaseVerificationFailure('manifest_checksum_invalid', 'A release package file checksum does not match the manifest.');
                }
                $manifestEntries[$path] = true;
            }

            $packageEntries = array_diff(array_keys($entries), [self::MANIFEST]);
            sort($packageEntries);
            $listedEntries = array_keys($manifestEntries);
            sort($listedEntries);
            if ($packageEntries !== $listedEntries) {
                throw new ReleaseVerificationFailure('manifest_incomplete', 'The release manifest does not list every package file.');
            }

            $schema = $manifest['schema_compatibility'] ?? null;
            if (!is_array($schema) || !is_string($schema['minimum'] ?? null) || !is_string($schema['maximum'] ?? null)) {
                throw new ReleaseVerificationFailure('manifest_compatibility_invalid', 'The release manifest does not declare schema compatibility.');
            }

            return new ReleaseVerificationResult($manifest['release_version'], $schema['minimum'], $schema['maximum'], $packageEntries);
        } finally {
            $zip->close();
        }
    }

    private function assertSafeEntry(string $name): void
    {
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, '/./') || str_contains($name, '../') || str_starts_with($name, '../') || str_ends_with($name, '.map')) {
            throw new ReleaseVerificationFailure('package_entry_invalid', 'The release package contains an unsafe entry name.');
        }

        if (str_ends_with($name, '/') || in_array($name, ['AGENTS.md', '.env', '.env.dev', '.env.test', '.env.prod'], true) || str_starts_with($name, '.env.')) {
            throw new ReleaseVerificationFailure('private_entry_forbidden', 'The release package contains a private or non-file entry.');
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                throw new ReleaseVerificationFailure('private_entry_forbidden', 'The release package contains a forbidden development or private entry.');
            }
        }

        foreach (['/database/', '/secrets/', '/logs/', '/exports/', '/diagnostics/', '/backups/'] as $fragment) {
            if (str_contains('/' . $name, $fragment)) {
                throw new ReleaseVerificationFailure('private_entry_forbidden', 'The release package contains private installation data.');
            }
        }
    }
}
