<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Branding;

use Formvex\Spoke\Domain\Branding\BrandingAsset;
use Formvex\Spoke\Domain\Branding\BrandingUpload;
use Formvex\Spoke\Domain\Branding\Contract\BrandingAssetStore;
use Formvex\Spoke\Domain\Branding\StagedBrandingAsset;
use Formvex\Spoke\Domain\Branding\ValidatedBrandingAsset;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final readonly class LocalBrandingAssetStore implements BrandingAssetStore
{
    public function __construct(private string $publicRoot)
    {
    }

    public function stage(PrivateStoragePaths $paths, BrandingUpload $upload, string $type): StagedBrandingAsset
    {
        if ($upload->error !== UPLOAD_ERR_OK || !is_file($upload->path) || is_link($upload->path)) {
            throw new InstallationSettingsFailure('branding_' . $type . '_upload_failed', 'The ' . $type . ' upload did not complete. Choose the file again and retry.');
        }

        $temporary = tempnam($paths->runtime, '.branding-');

        if ($temporary === false || !copy($upload->path, $temporary)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }

            throw new InstallationSettingsFailure('branding_' . $type . '_staging_failed', 'The ' . $type . ' could not be staged in private storage. Check storage permissions and try again.');
        }

        chmod($temporary, 0o600);

        return new StagedBrandingAsset($temporary, $type, $upload->originalName);
    }

    public function publish(StagedBrandingAsset $staged, ValidatedBrandingAsset $validated, int $revision): BrandingAsset
    {
        $directory = $this->directory();
        $filename = $staged->type . '-' . $revision . '-' . bin2hex(random_bytes(8)) . '.' . $validated->extension;
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . $filename . '.tmp-' . bin2hex(random_bytes(6));

        if (!copy($staged->path, $temporary) || !chmod($temporary, 0o644) || !rename($temporary, $target)) {
            @unlink($temporary);
            @unlink($target);
            throw new InstallationSettingsFailure('branding_asset_publish_failed', 'The validated ' . $staged->type . ' could not be published. Previous branding remains active; check public asset permissions.');
        }

        return new BrandingAsset($staged->type, $filename, $validated->mediaType, $validated->sha256, $validated->bytes, $validated->width, $validated->height);
    }

    public function cleanup(StagedBrandingAsset $staged): void
    {
        if (is_file($staged->path) && !is_link($staged->path)) {
            @unlink($staged->path);
        }
    }

    public function remove(?BrandingAsset $asset): bool
    {
        if ($asset === null) {
            return true;
        }

        if (!preg_match('/^(logo|favicon)-[0-9]+-[a-f0-9]{16}\.(?:png|jpe?g|svg|ico)$/', $asset->filename) || $asset->type !== $this->filenameType($asset->filename)) {
            return false;
        }

        $path = $this->directory() . DIRECTORY_SEPARATOR . $asset->filename;

        return !file_exists($path) || (is_file($path) && !is_link($path) && @unlink($path));
    }

    public function url(?BrandingAsset $asset): ?string
    {
        return $asset === null ? null : '/branding/' . $asset->filename;
    }

    private function directory(): string
    {
        if ($this->publicRoot === '' || !str_starts_with($this->publicRoot, DIRECTORY_SEPARATOR) || str_contains($this->publicRoot, "\0") || str_contains($this->publicRoot, '..')) {
            throw new InstallationSettingsFailure('branding_public_root_invalid', 'The public branding directory is not configured safely.');
        }

        $directory = rtrim($this->publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'branding';

        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new InstallationSettingsFailure('branding_public_directory_invalid', 'The public branding directory is not a safe directory. Remove the conflicting file or link and try again.');
        }

        if (!is_dir($directory) && (!mkdir($directory, 0o755, true) || !is_dir($directory))) {
            throw new InstallationSettingsFailure('branding_public_directory_unwritable', 'The public branding directory could not be created. Check public-directory permissions.');
        }

        chmod($directory, 0o755);

        return $directory;
    }

    private function filenameType(string $filename): string
    {
        return str_starts_with($filename, 'logo-') ? 'logo' : 'favicon';
    }
}
