<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Branding\BrandingAsset;
use Formvex\Spoke\Domain\Branding\BrandingSettings;
use Formvex\Spoke\Domain\Branding\Contract\BrandingSettingsStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use PDO;
use Throwable;

final class PdoBrandingSettingsStore implements BrandingSettingsStore
{
    public function get(PrivateStoragePaths $paths): BrandingSettings
    {
        $connection = $this->connection($paths);
        $statement = $connection->query('SELECT * FROM branding_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new InstallationSettingsFailure('branding_state_missing', 'The branding settings are not initialized. Run the installation repair procedure before opening Branding settings.');
        }

        try {
            return new BrandingSettings(
                $this->string($row, 'brand_name'),
                $this->string($row, 'slogan'),
                $this->integer($row, 'slogan_visible') === 1,
                $this->asset($row, 'logo'),
                $this->asset($row, 'favicon'),
                $this->integer($row, 'asset_revision'),
            );
        } catch (InstallationSettingsFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('branding_state_invalid', 'The saved branding settings contain an invalid value. Restore the previous branding or run the installation repair procedure.');
        }
    }

    public function save(PrivateStoragePaths $paths, BrandingSettings $settings, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'UPDATE branding_settings SET brand_name = :brand_name, slogan = :slogan, slogan_visible = :slogan_visible, '
                . 'logo_filename = :logo_filename, logo_media_type = :logo_media_type, logo_sha256 = :logo_sha256, logo_bytes = :logo_bytes, logo_width = :logo_width, logo_height = :logo_height, '
                . 'favicon_filename = :favicon_filename, favicon_media_type = :favicon_media_type, favicon_sha256 = :favicon_sha256, favicon_bytes = :favicon_bytes, favicon_width = :favicon_width, favicon_height = :favicon_height, '
                . 'asset_revision = :asset_revision, updated_at = :updated_at WHERE singleton_id = 1',
            );
            $statement->execute([
                'brand_name' => $settings->brandName,
                'slogan' => $settings->slogan,
                'slogan_visible' => $settings->sloganVisible ? 1 : 0,
                'logo_filename' => $settings->logo?->filename,
                'logo_media_type' => $settings->logo?->mediaType,
                'logo_sha256' => $settings->logo?->sha256,
                'logo_bytes' => $settings->logo?->bytes,
                'logo_width' => $settings->logo?->width,
                'logo_height' => $settings->logo?->height,
                'favicon_filename' => $settings->favicon?->filename,
                'favicon_media_type' => $settings->favicon?->mediaType,
                'favicon_sha256' => $settings->favicon?->sha256,
                'favicon_bytes' => $settings->favicon?->bytes,
                'favicon_width' => $settings->favicon?->width,
                'favicon_height' => $settings->favicon?->height,
                'asset_revision' => $settings->assetRevision,
                'updated_at' => $this->formatTimestamp($now),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new InstallationSettingsFailure('branding_save_failed', 'The branding settings could not be saved. Previous branding remains active; check the local database and try again.');
            }

            $connection->commit();
        } catch (InstallationSettingsFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new InstallationSettingsFailure('branding_save_failed', 'The branding settings could not be saved. Previous branding remains active; check the local database and try again.');
        }
    }

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at) VALUES (:event_name, :outcome, :occurred_at)');
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->formatTimestamp($now),
        ]);
    }

    /** @param array<mixed, mixed> $row */
    private function asset(array $row, string $type): ?BrandingAsset
    {
        $filename = $row[$type . '_filename'] ?? null;

        if ($filename === null) {
            return null;
        }

        if (!is_string($filename) || $filename === '') {
            throw new InstallationSettingsFailure('branding_state_invalid', 'The saved ' . $type . ' metadata is invalid.');
        }

        return new BrandingAsset(
            $type,
            $filename,
            $this->string($row, $type . '_media_type'),
            $this->string($row, $type . '_sha256'),
            $this->integer($row, $type . '_bytes'),
            $this->nullableInteger($row, $type . '_width'),
            $this->nullableInteger($row, $type . '_height'),
        );
    }

    /** @param array<mixed, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new InstallationSettingsFailure('branding_state_invalid', 'The saved branding settings contain an invalid text value.');
        }

        return $row[$key];
    }

    /** @param array<mixed, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || (!is_int($row[$key]) && !is_string($row[$key]) && !is_float($row[$key]))) {
            throw new InstallationSettingsFailure('branding_state_invalid', 'The saved branding settings contain an invalid numeric value.');
        }

        return (int) $row[$key];
    }

    /** @param array<mixed, mixed> $row */
    private function nullableInteger(array $row, string $key): ?int
    {
        return $row[$key] === null ? null : $this->integer($row, $key);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new InstallationSettingsFailure('installation_required', 'The local installation is not initialized. Run the installation command before opening Branding settings.');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');

            return $connection;
        } catch (Throwable) {
            throw new InstallationSettingsFailure('database_unavailable', 'The local database could not be opened. Check private-storage permissions and try again.');
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
}
