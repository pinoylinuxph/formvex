<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\StorageSettingsRepository;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use PDO;
use RuntimeException;
use Throwable;

final class PdoStorageSettingsRepository implements StorageSettingsRepository
{
    public function get(PrivateStoragePaths $paths): StorageSettings
    {
        $connection = $this->connection($paths);

        if (!$this->hasTable($connection, 'storage_settings')) {
            return StorageSettings::defaults();
        }

        $statement = $connection->query('SELECT allowance_bytes, normal_warning_percent, critical_warning_percent FROM storage_settings WHERE singleton_id = 1');
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return StorageSettings::defaults();
        }
        $row = $this->normalizeRow($row);

        try {
            return new StorageSettings(
                $this->integer($row, 'allowance_bytes'),
                $this->integer($row, 'normal_warning_percent'),
                $this->integer($row, 'critical_warning_percent'),
            );
        } catch (Throwable $failure) {
            throw new RuntimeException('The saved storage settings are invalid.', 0, $failure);
        }
    }

    public function save(PrivateStoragePaths $paths, StorageSettings $settings, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);

        if (!$this->hasTable($connection, 'storage_settings')) {
            throw new RuntimeException('The storage settings table is unavailable. Run the installation migration before saving storage settings.');
        }

        try {
            $connection->beginTransaction();
            $previous = $connection->query('SELECT allowance_bytes, normal_warning_percent, critical_warning_percent FROM storage_settings WHERE singleton_id = 1');
            $previousRow = $previous === false ? [] : $previous->fetch(PDO::FETCH_ASSOC);
            $previousRow = is_array($previousRow) ? $this->normalizeRow($previousRow) : [];
            $statement = $connection->prepare(
                'UPDATE storage_settings SET allowance_bytes = :allowance_bytes, normal_warning_percent = :normal_warning_percent, critical_warning_percent = :critical_warning_percent, updated_at = :updated_at WHERE singleton_id = 1',
            );
            $statement->execute([
                'allowance_bytes' => $settings->allowanceBytes,
                'normal_warning_percent' => $settings->normalWarningPercent,
                'critical_warning_percent' => $settings->criticalWarningPercent,
                'updated_at' => $this->formatTimestamp($now),
            ]);

            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('The storage settings record could not be updated.');
            }

            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) VALUES (:event_name, :outcome, :occurred_at, :resource_type, NULL, :metadata_json)',
            );
            $audit->execute([
                'event_name' => 'spoke.settings.storage_saved',
                'outcome' => 'success',
                'occurred_at' => $this->formatTimestamp($now),
                'resource_type' => 'storage_settings',
                'metadata_json' => json_encode([
                    'previous_allowance_bytes' => $this->integer($previousRow, 'allowance_bytes'),
                    'previous_normal_warning_percent' => $this->integer($previousRow, 'normal_warning_percent'),
                    'previous_critical_warning_percent' => $this->integer($previousRow, 'critical_warning_percent'),
                    'allowance_bytes' => $settings->allowanceBytes,
                    'normal_warning_percent' => $settings->normalWarningPercent,
                    'critical_warning_percent' => $settings->criticalWarningPercent,
                ], JSON_THROW_ON_ERROR),
            ]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The storage settings and audit record could not be saved. No storage setting was changed.', 0, $failure);
        }
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !is_string($row[$key] ?? null) && !is_float($row[$key] ?? null)) {
            throw new RuntimeException('The storage settings value is not numeric.');
        }

        return (int) $row[$key];
    }

    private function hasTable(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
