<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000020CreateReleaseNotifications implements Migration
{
    public function version(): string
    {
        return '000020';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS release_check_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)), '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec("INSERT OR IGNORE INTO release_check_settings (singleton_id, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z')");
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS release_check_state ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "status TEXT NOT NULL DEFAULT 'never_checked' CHECK (status IN ('disabled', 'never_checked', 'not_due', 'current', 'available', 'incompatible', 'failed', 'check_in_progress')), "
            . 'current_version TEXT NOT NULL DEFAULT \'unversioned\', '
            . 'available_version TEXT NULL, '
            . "severity TEXT NOT NULL DEFAULT 'normal' CHECK (severity IN ('normal', 'important', 'critical')), "
            . 'minimum_supported_version TEXT NULL, '
            . 'release_notes_url TEXT NULL, '
            . 'package_url TEXT NULL, '
            . 'package_sha256 TEXT NULL, '
            . 'published_at TEXT NULL, '
            . 'last_attempt_at TEXT NULL, '
            . 'last_successful_at TEXT NULL, '
            . 'failure_code TEXT NULL, '
            . 'failure_message TEXT NULL, '
            . 'acknowledged_version TEXT NULL, '
            . 'acknowledged_at TEXT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec("INSERT OR IGNORE INTO release_check_state (singleton_id, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z')");
    }
}
