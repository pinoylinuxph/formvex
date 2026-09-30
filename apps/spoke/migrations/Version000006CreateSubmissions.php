<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000006CreateSubmissions implements Migration
{
    public function version(): string
    {
        return '000006';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS submissions ('
            . 'id INTEGER PRIMARY KEY, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . 'form_id INTEGER NOT NULL, '
            . 'configuration_version_id INTEGER NOT NULL, '
            . 'configuration_version INTEGER NOT NULL CHECK (configuration_version >= 1), '
            . 'page_path TEXT NOT NULL, '
            . 'form_marker TEXT NOT NULL, '
            . 'recipient TEXT NOT NULL, '
            . 'subject TEXT NOT NULL, '
            . 'fields_json TEXT NOT NULL, '
            . "classification TEXT NOT NULL CHECK (classification IN ('normal', 'suspected_spam')), "
            . "state TEXT NOT NULL DEFAULT 'accepted' CHECK (state IN ('accepted', 'handled', 'trashed')), "
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (form_id) REFERENCES form_configurations(id) ON DELETE RESTRICT, '
            . 'FOREIGN KEY (configuration_version_id) REFERENCES form_configuration_versions(id) ON DELETE RESTRICT'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_form_created '
            . 'ON submissions (form_id, created_at DESC)',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submissions_classification_state '
            . 'ON submissions (classification, state, created_at DESC)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS submission_attempts ('
            . 'id INTEGER PRIMARY KEY, '
            . 'public_form_id TEXT NOT NULL, '
            . 'attempt_id TEXT NOT NULL, '
            . 'submission_id INTEGER NOT NULL, '
            . 'payload_hash TEXT NOT NULL, '
            . 'receipt_id TEXT NOT NULL UNIQUE, '
            . 'accepted_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE RESTRICT, '
            . 'UNIQUE (public_form_id, attempt_id)'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submission_attempts_expiry '
            . 'ON submission_attempts (expires_at)',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_submission_attempts_receipt '
            . 'ON submission_attempts (receipt_id)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_jobs ('
            . 'id INTEGER PRIMARY KEY, '
            . 'job_id TEXT NOT NULL UNIQUE, '
            . 'submission_id INTEGER NOT NULL UNIQUE, '
            . "state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'processing', 'sent', 'failed', 'uncertain')), "
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0), '
            . 'due_at TEXT NOT NULL, '
            . 'last_error_code TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE RESTRICT'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_delivery_jobs_due '
            . 'ON delivery_jobs (state, due_at)',
        );
    }
}
