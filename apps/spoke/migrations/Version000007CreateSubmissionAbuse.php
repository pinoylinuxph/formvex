<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000007CreateSubmissionAbuse implements Migration
{
    public function version(): string
    {
        return '000007';
    }

    public function up(PDO $connection): void
    {
        $this->addColumnIfMissing($connection, 'form_configuration_versions', 'captcha_enabled', 'INTEGER NOT NULL DEFAULT 0 CHECK (captcha_enabled IN (0, 1))');
        $this->addColumnIfMissing($connection, 'form_configuration_versions', 'captcha_site_key', "TEXT NOT NULL DEFAULT ''");

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS submission_abuse_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'per_form_short_limit INTEGER NOT NULL DEFAULT 5, '
            . 'per_form_short_window_seconds INTEGER NOT NULL DEFAULT 600, '
            . 'per_form_hour_limit INTEGER NOT NULL DEFAULT 20, '
            . 'per_form_hour_window_seconds INTEGER NOT NULL DEFAULT 3600, '
            . 'installation_hour_limit INTEGER NOT NULL DEFAULT 30, '
            . 'installation_hour_window_seconds INTEGER NOT NULL DEFAULT 3600, '
            . 'flood_minute_limit INTEGER NOT NULL DEFAULT 60, '
            . 'flood_minute_window_seconds INTEGER NOT NULL DEFAULT 60, '
            . 'flood_hour_limit INTEGER NOT NULL DEFAULT 300, '
            . 'flood_hour_window_seconds INTEGER NOT NULL DEFAULT 3600, '
            . "trusted_proxy_cidrs TEXT NOT NULL DEFAULT '', "
            . "turnstile_secret_slot TEXT NOT NULL DEFAULT 'a', "
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO submission_abuse_settings (singleton_id, created_at, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z', '1970-01-01T00:00:00.000000Z')",
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS abuse_rate_counters ('
            . 'id INTEGER PRIMARY KEY, '
            . 'scope TEXT NOT NULL, '
            . 'identity TEXT NOT NULL, '
            . "form_public_id TEXT NOT NULL DEFAULT '', "
            . 'bucket_start INTEGER NOT NULL, '
            . 'window_seconds INTEGER NOT NULL, '
            . 'count INTEGER NOT NULL CHECK (count >= 0), '
            . 'updated_at TEXT NOT NULL, '
            . 'UNIQUE (scope, identity, form_public_id, bucket_start, window_seconds)'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_abuse_rate_counters_expiry ON abuse_rate_counters (bucket_start, window_seconds)');
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS captcha_outage_state ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'is_open INTEGER NOT NULL DEFAULT 0 CHECK (is_open IN (0, 1)), '
            . 'opened_at TEXT NULL, '
            . 'last_failure_at TEXT NULL, '
            . 'last_failure_code TEXT NULL, '
            . 'last_alert_at TEXT NULL, '
            . 'last_success_at TEXT NULL'
            . ')',
        );
        $connection->exec('INSERT OR IGNORE INTO captcha_outage_state (singleton_id) VALUES (1)');
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
