<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Abuse\Contract\CaptchaSecretStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Throwable;

final class LocalCaptchaSecretStore implements CaptchaSecretStore
{
    public function isConfigured(PrivateStoragePaths $paths, string $slot): bool
    {
        $path = $this->path($paths, $slot);
        $permissions = is_file($path) && !is_link($path) && is_readable($path) ? fileperms($path) : false;

        return $permissions !== false && ($permissions & 0o077) === 0;
    }

    public function write(PrivateStoragePaths $paths, string $slot, string $secret): void
    {
        if ($secret === '' || strlen($secret) > 4096 || str_contains($secret, "\0")) {
            throw new InstallationSettingsFailure('turnstile_secret_invalid', 'Enter the Turnstile secret key before saving CAPTCHA settings.');
        }

        $path = $this->path($paths, $slot);
        $temporaryPath = $paths->secrets . DIRECTORY_SEPARATOR . '.turnstile-secret-' . $slot . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($secret, true) . ";\n";
        $handle = fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new InstallationSettingsFailure('turnstile_secret_write_failed', 'Formvex could not create the protected Turnstile secret file. Check private-storage permissions.');
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !chmod($temporaryPath, 0o600)) {
                throw new InstallationSettingsFailure('turnstile_secret_write_failed', 'Formvex could not finish protecting the Turnstile secret. The previous secret remains active.');
            }
        } catch (Throwable $failure) {
            fclose($handle);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            if ($failure instanceof InstallationSettingsFailure) {
                throw $failure;
            }
            throw new InstallationSettingsFailure('turnstile_secret_write_failed', 'Formvex could not write the protected Turnstile secret. The previous secret remains active.');
        }

        fclose($handle);

        if (!rename($temporaryPath, $path)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new InstallationSettingsFailure('turnstile_secret_publish_failed', 'Formvex could not publish the protected Turnstile secret. The previous secret remains active.');
        }
        chmod($path, 0o600);
    }

    public function read(PrivateStoragePaths $paths, string $slot): string
    {
        $path = $this->path($paths, $slot);

        if (!$this->isConfigured($paths, $slot)) {
            throw new InstallationSettingsFailure('turnstile_secret_missing', 'The Turnstile secret key is not configured. Save it before enabling CAPTCHA.');
        }

        try {
            $value = include $path;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('turnstile_secret_read_failed', 'Formvex could not read the protected Turnstile secret. Check private-storage permissions.');
        }

        if (!is_string($value) || $value === '' || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new InstallationSettingsFailure('turnstile_secret_invalid', 'The protected Turnstile secret is invalid. Replace it before enabling CAPTCHA.');
        }

        return $value;
    }

    public function remove(PrivateStoragePaths $paths, string $slot): void
    {
        $path = $this->path($paths, $slot);

        if (is_file($path) && !unlink($path)) {
            throw new InstallationSettingsFailure('turnstile_secret_cleanup_failed', 'Formvex saved the replacement Turnstile secret but could not remove the inactive copy.');
        }
    }

    private function path(PrivateStoragePaths $paths, string $slot): string
    {
        if (!in_array($slot, ['a', 'b'], true)) {
            throw new InstallationSettingsFailure('turnstile_secret_slot_invalid', 'Formvex found an invalid Turnstile secret slot.');
        }

        return $paths->secrets . DIRECTORY_SEPARATOR . 'turnstile-secret-' . $slot . '.php';
    }
}
