<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000013CreateRetentionAndCleanup implements Migration
{
    public function version(): string
    {
        return '000013';
    }

    public function up(PDO $connection): void
    {
        $this->addColumnIfMissing($connection, 'installation_settings', 'ordinary_retention_days', 'INTEGER NOT NULL DEFAULT 30 CHECK (ordinary_retention_days BETWEEN 1 AND 365)');
        $this->addColumnIfMissing($connection, 'installation_settings', 'uncertain_retention_days', 'INTEGER NOT NULL DEFAULT 90 CHECK (uncertain_retention_days BETWEEN 1 AND 365)');
        $this->addColumnIfMissing($connection, 'installation_settings', 'audit_retention_days', 'INTEGER NOT NULL DEFAULT 30 CHECK (audit_retention_days BETWEEN 1 AND 365)');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_run_at', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_status', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_scanned', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_deleted', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_deferred', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_failed', 'INTEGER NOT NULL DEFAULT 0');
        $this->addColumnIfMissing($connection, 'installation_settings', 'retention_last_error_code', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'submissions', 'restored_at', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'submissions', 'recovery_deadline', 'TEXT NULL');

        $connection->exec('CREATE INDEX IF NOT EXISTS idx_submissions_retention_state ON submissions (state, trashed_at, handled_at, created_at)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_audit_events_retention ON audit_events (occurred_at, id)');
    }

    private function addColumnIfMissing(PDO $connection, string $table, string $column, string $definition): void
    {
        if (!$this->hasColumn($connection, $table, $column)) {
            $connection->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function hasColumn(PDO $connection, string $table, string $column): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
    }
}
