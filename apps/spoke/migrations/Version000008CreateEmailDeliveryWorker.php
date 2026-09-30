<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000008CreateEmailDeliveryWorker implements Migration
{
    public function version(): string
    {
        return '000008';
    }

    public function up(PDO $connection): void
    {
        $this->addColumnIfMissing($connection, 'installation_settings', 'smtp_attempts_per_minute', 'INTEGER NOT NULL DEFAULT 10 CHECK (smtp_attempts_per_minute BETWEEN 1 AND 60)');
        $this->addColumnIfMissing($connection, 'delivery_jobs', 'snapshot_json', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'delivery_jobs', 'lease_token', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'delivery_jobs', 'lease_expires_at', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'delivery_jobs', 'last_outcome', 'TEXT NULL');

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_attempts ('
            . 'id INTEGER PRIMARY KEY, '
            . 'job_id INTEGER NOT NULL, '
            . 'attempt_number INTEGER NOT NULL CHECK (attempt_number BETWEEN 1 AND 6), '
            . "outcome TEXT NOT NULL CHECK (outcome IN ('started', 'accepted', 'temporary_failure', 'permanent_failure', 'uncertain')), "
            . 'error_code TEXT NULL, '
            . 'started_at TEXT NOT NULL, '
            . 'completed_at TEXT NULL, '
            . 'next_due_at TEXT NULL, '
            . 'FOREIGN KEY (job_id) REFERENCES delivery_jobs(id) ON DELETE RESTRICT, '
            . 'UNIQUE (job_id, attempt_number)'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_delivery_attempts_job_started ON delivery_attempts (job_id, started_at DESC)');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_pacing ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'window_start INTEGER NOT NULL DEFAULT 0, '
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0)'
            . ')',
        );
        $connection->exec('INSERT OR IGNORE INTO delivery_pacing (singleton_id) VALUES (1)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_delivery_jobs_processing_lease ON delivery_jobs (state, lease_expires_at)');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_alerts ('
            . 'id INTEGER PRIMARY KEY, '
            . 'event_code TEXT NOT NULL, '
            . "state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'processing', 'sent', 'failed', 'uncertain')), "
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 6), '
            . 'due_at TEXT NOT NULL, '
            . 'lease_token TEXT NULL, '
            . 'lease_expires_at TEXT NULL, '
            . 'last_error_code TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'UNIQUE (event_code, created_at)'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_delivery_alerts_due ON delivery_alerts (state, due_at)');
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
