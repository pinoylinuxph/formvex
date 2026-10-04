<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000018CreateFormChangeObservations implements Migration
{
    public function version(): string
    {
        return '000018';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_change_observations ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . 'form_public_id TEXT NOT NULL, '
            . 'configuration_version INTEGER NOT NULL CHECK (configuration_version >= 1), '
            . 'page_path TEXT NOT NULL, '
            . 'form_marker TEXT NOT NULL, '
            . 'fingerprint TEXT NOT NULL, '
            . 'status TEXT NOT NULL CHECK (status IN (\'open\', \'resolved\', \'discarded\')), '
            . 'differences_json TEXT NOT NULL, '
            . 'first_observed_at TEXT NOT NULL, '
            . 'last_observed_at TEXT NOT NULL, '
            . 'resolved_at TEXT NULL, '
            . 'resolved_by TEXT NULL'
            . ')',
        );
        $connection->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_form_change_observations_open_dedup
             ON form_change_observations (form_public_id, configuration_version, page_path, form_marker, fingerprint)
             WHERE status = 'open'",
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_change_observations_open
             ON form_change_observations (status, last_observed_at DESC)',
        );
    }
}
