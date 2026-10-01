<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000011CreateSubmissionReview implements Migration
{
    public function version(): string
    {
        return '000011';
    }

    public function up(PDO $connection): void
    {
        $this->addColumn($connection, 'submissions', 'handled_at TEXT NULL');
        $this->addColumn($connection, 'submissions', 'trashed_at TEXT NULL');
        $this->addColumn($connection, 'submissions', "pre_trash_state TEXT NULL CHECK (pre_trash_state IN ('accepted', 'handled'))");
        $this->addColumn($connection, 'audit_events', 'resource_type TEXT NULL');
        $this->addColumn($connection, 'audit_events', 'resource_public_id TEXT NULL');

        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_review_list '
            . 'ON submissions (state, classification, created_at DESC)',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_review_form '
            . 'ON submissions (form_id, state, created_at DESC)',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_audit_events_resource '
            . 'ON audit_events (resource_type, resource_public_id, occurred_at DESC)',
        );
    }

    private function addColumn(PDO $connection, string $table, string $definition): void
    {
        [$column] = explode(' ', $definition, 2);
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        if ($statement->fetchColumn() !== false) {
            return;
        }

        $connection->exec('ALTER TABLE ' . $this->identifier($table) . ' ADD COLUMN ' . $definition);
    }

    private function identifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
