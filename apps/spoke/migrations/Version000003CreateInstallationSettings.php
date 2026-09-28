<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000003CreateInstallationSettings implements Migration
{
    public function version(): string
    {
        return '000003';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS installation_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "website_display_name TEXT NOT NULL DEFAULT 'Local Spoke', "
            . "bare_domain TEXT NOT NULL DEFAULT '', "
            . 'www_alias TEXT NULL, '
            . "operational_alert_email TEXT NOT NULL DEFAULT '', "
            . "sender_email TEXT NOT NULL DEFAULT '', "
            . 'sender_name TEXT NULL, '
            . "smtp_host TEXT NOT NULL DEFAULT '', "
            . 'smtp_port INTEGER NOT NULL DEFAULT 465 CHECK (smtp_port BETWEEN 1 AND 65535), '
            . "smtp_encryption TEXT NOT NULL DEFAULT 'smtps' CHECK (smtp_encryption IN ('smtps', 'starttls')), "
            . "smtp_username TEXT NOT NULL DEFAULT '', "
            . 'smtp_timeout_seconds INTEGER NOT NULL DEFAULT 10 CHECK (smtp_timeout_seconds BETWEEN 3 AND 60), '
            . 'maximum_login_failures INTEGER NOT NULL DEFAULT 5 CHECK (maximum_login_failures BETWEEN 3 AND 20), '
            . 'login_window_minutes INTEGER NOT NULL DEFAULT 15 CHECK (login_window_minutes BETWEEN 5 AND 60), '
            . 'login_cooldown_minutes INTEGER NOT NULL DEFAULT 15 CHECK (login_cooldown_minutes BETWEEN 5 AND 120), '
            . 'smtp_configuration_revision INTEGER NOT NULL DEFAULT 1 CHECK (smtp_configuration_revision >= 1), '
            . "smtp_secret_slot TEXT NOT NULL DEFAULT 'a' CHECK (smtp_secret_slot IN ('a', 'b')), "
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS smtp_test_state ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "status TEXT NOT NULL DEFAULT 'not_configured' CHECK (status IN ('not_configured', 'passed', 'failed', 'uncertain', 'stale')), "
            . 'tested_revision INTEGER NOT NULL DEFAULT 0 CHECK (tested_revision >= 0), '
            . 'failure_code TEXT NULL, '
            . 'summary TEXT NULL, '
            . 'requested_at TEXT NULL, '
            . 'completed_at TEXT NULL, '
            . 'cooldown_until TEXT NULL'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO installation_settings (singleton_id, created_at, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z', '1970-01-01T00:00:00.000000Z')",
        );
        $connection->exec(
            'INSERT OR IGNORE INTO smtp_test_state (singleton_id) VALUES (1)',
        );
    }
}
