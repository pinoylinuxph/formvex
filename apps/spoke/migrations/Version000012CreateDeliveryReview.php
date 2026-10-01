<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000012CreateDeliveryReview implements Migration
{
    public function version(): string
    {
        return '000012';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_attempt_cycles ('
            . 'id INTEGER PRIMARY KEY, '
            . 'delivery_job_id INTEGER NOT NULL, '
            . 'cycle_number INTEGER NOT NULL CHECK (cycle_number >= 1), '
            . "origin TEXT NOT NULL CHECK (origin IN ('automatic', 'manual')), "
            . "state TEXT NOT NULL CHECK (state IN ('queued', 'processing', 'sent', 'failed', 'uncertain')), "
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 6), '
            . 'last_error_code TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (delivery_job_id) REFERENCES delivery_jobs(id) ON DELETE RESTRICT, '
            . 'UNIQUE (delivery_job_id, cycle_number)'
            . ')',
        );
        $this->addColumnIfMissing($connection, 'delivery_jobs', 'active_cycle_id', 'INTEGER NULL');

        // Use set-based SQL here. The installation store runs migrations inside a
        // SQLite transaction, and keeping native prepared statements open while
        // replacing delivery_attempts can leave SQLite holding a schema lock.
        // Legacy delivery_jobs also allowed attempt counts above the six-attempt
        // cycle limit, so clamp those values during the upgrade.
        $connection->exec(
            'INSERT OR IGNORE INTO delivery_attempt_cycles '
            . '(delivery_job_id, cycle_number, origin, state, attempt_count, last_error_code, created_at, updated_at) '
            . 'SELECT id, 1, \'automatic\', '
            . 'CASE WHEN state IN (\'queued\', \'processing\', \'sent\', \'failed\', \'uncertain\') THEN state ELSE \'queued\' END, '
            . 'CASE WHEN attempt_count IS NULL OR attempt_count < 0 THEN 0 WHEN attempt_count > 6 THEN 6 ELSE attempt_count END, '
            . 'last_error_code, '
            . 'CASE WHEN created_at IS NULL OR created_at = \'\' THEN \'1970-01-01T00:00:00.000000Z\' ELSE created_at END, '
            . 'CASE WHEN updated_at IS NULL OR updated_at = \'\' THEN \'1970-01-01T00:00:00.000000Z\' ELSE updated_at END '
            . 'FROM delivery_jobs',
        );
        $connection->exec(
            'UPDATE delivery_jobs SET active_cycle_id = ('
            . 'SELECT id FROM delivery_attempt_cycles '
            . 'WHERE delivery_job_id = delivery_jobs.id AND cycle_number = 1'
            . ') WHERE active_cycle_id IS NULL',
        );

        if (!$this->hasColumn($connection, 'delivery_attempts', 'cycle_id')) {
            $connection->exec(
                'CREATE TABLE delivery_attempts_new ('
                . 'id INTEGER PRIMARY KEY, '
                . 'job_id INTEGER NOT NULL, '
                . 'cycle_id INTEGER NOT NULL, '
                . 'attempt_number INTEGER NOT NULL CHECK (attempt_number BETWEEN 1 AND 6), '
                . "outcome TEXT NOT NULL CHECK (outcome IN ('started', 'accepted', 'temporary_failure', 'permanent_failure', 'uncertain')), "
                . 'error_code TEXT NULL, '
                . 'started_at TEXT NOT NULL, '
                . 'completed_at TEXT NULL, '
                . 'next_due_at TEXT NULL, '
                . 'FOREIGN KEY (job_id) REFERENCES delivery_jobs(id) ON DELETE RESTRICT, '
                . 'FOREIGN KEY (cycle_id) REFERENCES delivery_attempt_cycles(id) ON DELETE RESTRICT, '
                . 'UNIQUE (cycle_id, attempt_number)'
                . ')',
            );
            $connection->exec(
                'INSERT INTO delivery_attempts_new (id, job_id, cycle_id, attempt_number, outcome, error_code, started_at, completed_at, next_due_at) '
                . 'SELECT a.id, a.job_id, c.id, a.attempt_number, a.outcome, a.error_code, a.started_at, a.completed_at, a.next_due_at '
                . 'FROM delivery_attempts a INNER JOIN delivery_attempt_cycles c ON c.delivery_job_id = a.job_id AND c.cycle_number = 1',
            );
            $connection->exec('DROP TABLE delivery_attempts');
            $connection->exec('ALTER TABLE delivery_attempts_new RENAME TO delivery_attempts');
        }

        $connection->exec('CREATE INDEX IF NOT EXISTS idx_delivery_cycles_job ON delivery_attempt_cycles (delivery_job_id, cycle_number DESC)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_delivery_attempts_cycle ON delivery_attempts (cycle_id, started_at DESC)');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_worker_heartbeat ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'last_success_at TEXT NULL, '
            . 'last_result TEXT NULL, '
            . 'last_claimed INTEGER NOT NULL DEFAULT 0, '
            . 'last_sent INTEGER NOT NULL DEFAULT 0, '
            . 'last_failed INTEGER NOT NULL DEFAULT 0, '
            . 'last_uncertain INTEGER NOT NULL DEFAULT 0, '
            . 'last_deferred INTEGER NOT NULL DEFAULT 0, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec("INSERT OR IGNORE INTO delivery_worker_heartbeat (singleton_id, updated_at) VALUES (1, '')");
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
