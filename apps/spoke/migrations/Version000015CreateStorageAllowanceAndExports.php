<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000015CreateStorageAllowanceAndExports implements Migration
{
    public function version(): string
    {
        return '000015';
    }

    public function up(PDO $connection): void
    {
        $this->addColumnIfMissing($connection, 'audit_events', 'metadata_json', "TEXT NOT NULL DEFAULT '{}'");
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS storage_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'allowance_bytes INTEGER NOT NULL DEFAULT 2000000000 CHECK (allowance_bytes BETWEEN 1000000000 AND 10000000000), '
            . 'normal_warning_percent INTEGER NOT NULL DEFAULT 80 CHECK (normal_warning_percent BETWEEN 1 AND 99), '
            . 'critical_warning_percent INTEGER NOT NULL DEFAULT 90 CHECK (critical_warning_percent BETWEEN 2 AND 99), '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'CHECK (normal_warning_percent < critical_warning_percent)'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO storage_settings (singleton_id, created_at, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z', '1970-01-01T00:00:00.000000Z')",
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS storage_exports ('
            . 'id INTEGER PRIMARY KEY, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . 'row_count INTEGER NOT NULL CHECK (row_count BETWEEN 0 AND 10000), '
            . 'file_size_bytes INTEGER NOT NULL CHECK (file_size_bytes >= 0), '
            . "filter_summary TEXT NOT NULL DEFAULT '', "
            . 'created_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'downloaded_at TEXT NULL, '
            . "status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'downloaded', 'expired', 'failed'))"
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_storage_exports_expiry ON storage_exports (expires_at, status)');
    }

    private function addColumnIfMissing(PDO $connection, string $table, string $column, string $definition): void
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        if ($statement->fetchColumn() === false) {
            $connection->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
}
