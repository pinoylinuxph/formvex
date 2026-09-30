<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000009CreateFormActivation implements Migration
{
    public function version(): string
    {
        return '000009';
    }

    public function up(PDO $connection): void
    {
        $this->addColumnIfMissing($connection, 'submissions', 'qualification_id', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'submissions', 'is_qualification_test', 'INTEGER NOT NULL DEFAULT 0 CHECK (is_qualification_test IN (0, 1))');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_status', "TEXT NOT NULL DEFAULT 'not_run' CHECK (end_to_end_status IN ('not_run', 'accepted', 'sent', 'failed', 'uncertain', 'stale'))");
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_failure_code', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_fingerprint', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_accepted_at', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_delivery_accepted_at', 'TEXT NULL');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_submission_id', 'INTEGER NULL');
        $this->addColumnIfMissing($connection, 'form_configuration_evidence', 'end_to_end_qualification_id', 'TEXT NULL');

        $connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_qualification_submission ON submissions (qualification_id) WHERE qualification_id IS NOT NULL');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_qualification_capabilities ('
            . 'id INTEGER PRIMARY KEY, '
            . 'capability_id TEXT NOT NULL UNIQUE, '
            . 'capability_token_hash TEXT NOT NULL UNIQUE, '
            . 'session_hash TEXT NOT NULL, '
            . 'public_form_id TEXT NOT NULL, '
            . 'version_number INTEGER NOT NULL CHECK (version_number >= 1), '
            . 'host TEXT NOT NULL, '
            . 'path TEXT NOT NULL, '
            . 'form_marker TEXT NOT NULL, '
            . 'evidence_fingerprint TEXT NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'redeemed_at TEXT NULL, '
            . 'qualification_id TEXT NULL, '
            . 'qualification_token_hash TEXT NULL UNIQUE, '
            . 'qualification_expires_at TEXT NULL, '
            . 'submitted_at TEXT NULL, '
            . 'submission_id INTEGER NULL'
            . ')',
        );
        $this->addColumnIfMissing($connection, 'form_qualification_capabilities', 'qualification_id', 'TEXT NULL');
        $connection->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_form_qualification_id ON form_qualification_capabilities (qualification_id) WHERE qualification_id IS NOT NULL');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_form_qualification_capabilities_session ON form_qualification_capabilities (session_hash, expires_at)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_form_qualification_capabilities_form ON form_qualification_capabilities (public_form_id, version_number, expires_at)');
    }

    private function addColumnIfMissing(PDO $connection, string $table, string $column, string $definition): void
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        if ($statement->fetchColumn() === false) {
            $connection->exec('ALTER TABLE ' . $this->identifier($table) . ' ADD COLUMN ' . $this->identifier($column) . ' ' . $definition);
        }
    }

    private function identifier(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }
}
