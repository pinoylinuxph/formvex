<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Security;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpSecretStore;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Throwable;

final class LocalSmtpSecretStore implements SmtpSecretStore
{
    public function isConfigured(PrivateStoragePaths $paths, string $slot): bool
    {
        $path = $this->path($paths, $slot);

        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return false;
        }

        $permissions = fileperms($path);

        return $permissions !== false && ($permissions & 0o077) === 0;
    }

    public function write(PrivateStoragePaths $paths, string $slot, string $password): void
    {
        if ($password === '' || strlen($password) > 4096 || str_contains($password, "\0")) {
            throw new InstallationSettingsFailure('smtp_password_invalid', 'Enter the SMTP password before saving the SMTP settings.');
        }

        $path = $this->path($paths, $slot);

        if (is_link($path)) {
            throw new InstallationSettingsFailure('smtp_secret_store_invalid', 'Formvex cannot safely update the SMTP secret because its private secret file is a symbolic link.');
        }

        $temporaryPath = $paths->secrets . DIRECTORY_SEPARATOR . '.smtp-password-' . $slot . '-' . bin2hex(random_bytes(8)) . '.tmp';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($password, true) . ";\n";
        $handle = fopen($temporaryPath, 'x');

        if ($handle === false) {
            throw new InstallationSettingsFailure('smtp_secret_write_failed', 'Formvex could not create the protected SMTP secret file. Check private-storage permissions.');
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new InstallationSettingsFailure('smtp_secret_write_failed', 'Formvex could not finish writing the protected SMTP secret. The previous secret remains active.');
            }

            if (!chmod($temporaryPath, 0o600)) {
                throw new InstallationSettingsFailure('smtp_secret_permissions_failed', 'Formvex could not apply owner-only permissions to the SMTP secret. The previous secret remains active.');
            }
        } catch (Throwable $failure) {
            fclose($handle);
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }

            if ($failure instanceof InstallationSettingsFailure) {
                throw $failure;
            }

            throw new InstallationSettingsFailure('smtp_secret_write_failed', 'Formvex could not write the protected SMTP secret. The previous secret remains active.');
        }

        fclose($handle);

        if (!rename($temporaryPath, $path)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new InstallationSettingsFailure('smtp_secret_publish_failed', 'Formvex could not publish the protected SMTP secret. The previous secret remains active.');
        }

        chmod($path, 0o600);
    }

    public function read(PrivateStoragePaths $paths, string $slot): string
    {
        $path = $this->path($paths, $slot);

        if (!$this->isConfigured($paths, $slot)) {
            throw new InstallationSettingsFailure('smtp_password_missing', 'The SMTP password is not configured. Enter it and save the SMTP settings before testing delivery.');
        }

        try {
            $value = include $path;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('smtp_secret_read_failed', 'Formvex could not read the protected SMTP secret. Check private-storage permissions.');
        }

        if (!is_string($value) || $value === '' || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new InstallationSettingsFailure('smtp_secret_invalid', 'The protected SMTP secret is invalid. Replace the SMTP password before testing delivery.');
        }

        return $value;
    }

    public function remove(PrivateStoragePaths $paths, string $slot): void
    {
        $path = $this->path($paths, $slot);

        if (!is_file($path) || is_link($path) || !unlink($path)) {
            throw new InstallationSettingsFailure('smtp_secret_cleanup_failed', 'Formvex saved the new SMTP secret but could not remove the inactive older copy. The active settings remain usable.');
        }
    }

    private function path(PrivateStoragePaths $paths, string $slot): string
    {
        if (!in_array($slot, ['a', 'b'], true)) {
            throw new InstallationSettingsFailure('smtp_secret_slot_invalid', 'Formvex found an invalid SMTP secret slot.');
        }

        return $paths->secrets . DIRECTORY_SEPARATOR . 'smtp-password-' . $slot . '.php';
    }
}
