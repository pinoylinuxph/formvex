<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000004CreateFormConfiguration implements Migration
{
    public function version(): string
    {
        return '000004';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configurations ('
            . 'id INTEGER PRIMARY KEY, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . 'display_name TEXT NOT NULL, '
            . 'deleted_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configuration_versions ('
            . 'id INTEGER PRIMARY KEY, '
            . 'form_id INTEGER NOT NULL, '
            . 'version_number INTEGER NOT NULL CHECK (version_number >= 0), '
            . "state TEXT NOT NULL CHECK (state IN ('draft', 'published', 'active', 'disabled')), "
            . 'revision INTEGER NOT NULL CHECK (revision >= 1), '
            . "recipient TEXT NOT NULL DEFAULT '', "
            . "subject TEXT NOT NULL DEFAULT '', "
            . 'evidence_revision INTEGER NOT NULL DEFAULT 1 CHECK (evidence_revision >= 1), '
            . 'published_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (form_id) REFERENCES form_configurations(id) ON DELETE CASCADE, '
            . 'UNIQUE (form_id, version_number)'
            . ')',
        );
        $connection->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_form_configuration_draft ON form_configuration_versions (form_id) WHERE state = 'draft'",
        );
        $connection->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_form_configuration_active ON form_configuration_versions (form_id) WHERE state = 'active'",
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_configuration_versions_state ON form_configuration_versions (form_id, state, version_number DESC)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configuration_version_pages ('
            . 'version_id INTEGER PRIMARY KEY, '
            . 'host TEXT NOT NULL, '
            . 'path TEXT NOT NULL, '
            . 'form_marker TEXT NOT NULL, '
            . 'FOREIGN KEY (version_id) REFERENCES form_configuration_versions(id) ON DELETE CASCADE, '
            . 'UNIQUE (host, path, form_marker, version_id)'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_configuration_pages_lookup '
            . 'ON form_configuration_version_pages (host, path, form_marker)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configuration_version_fields ('
            . 'id INTEGER PRIMARY KEY, '
            . 'version_id INTEGER NOT NULL, '
            . 'field_key TEXT NOT NULL, '
            . 'control_name TEXT NOT NULL, '
            . 'control_type TEXT NOT NULL, '
            . 'display_label TEXT NOT NULL, '
            . 'parameter_key TEXT NOT NULL, '
            . 'ordinal INTEGER NOT NULL CHECK (ordinal >= 0 AND ordinal < 100), '
            . 'is_required INTEGER NOT NULL CHECK (is_required IN (0, 1)), '
            . 'max_length INTEGER NOT NULL CHECK (max_length BETWEEN 1 AND 10000), '
            . 'FOREIGN KEY (version_id) REFERENCES form_configuration_versions(id) ON DELETE CASCADE, '
            . 'UNIQUE (version_id, field_key), '
            . 'UNIQUE (version_id, control_name)'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_configuration_fields_order '
            . 'ON form_configuration_version_fields (version_id, ordinal)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configuration_field_choices ('
            . 'id INTEGER PRIMARY KEY, '
            . 'field_id INTEGER NOT NULL, '
            . 'choice_value TEXT NOT NULL, '
            . 'choice_label TEXT NOT NULL, '
            . 'FOREIGN KEY (field_id) REFERENCES form_configuration_version_fields(id) ON DELETE CASCADE, '
            . 'UNIQUE (field_id, choice_value)'
            . ')',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_configuration_evidence ('
            . 'version_id INTEGER PRIMARY KEY, '
            . 'smtp_test_revision INTEGER NULL, '
            . 'end_to_end_tested_at TEXT NULL, '
            . 'evidence_revision INTEGER NOT NULL DEFAULT 1 CHECK (evidence_revision >= 1), '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (version_id) REFERENCES form_configuration_versions(id) ON DELETE CASCADE'
            . ')',
        );
    }
}
