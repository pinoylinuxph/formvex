<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000019CreateScheduledBackupLifecycle implements Migration
{
    public function version(): string
    {
        return '000019';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS scheduled_backup_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)), '
            . "frequency TEXT NOT NULL DEFAULT 'monthly' CHECK (frequency IN ('daily', 'weekly', 'monthly')), "
            . 'weekday INTEGER NOT NULL DEFAULT 0 CHECK (weekday BETWEEN 0 AND 6), '
            . 'hour INTEGER NOT NULL DEFAULT 0 CHECK (hour BETWEEN 0 AND 23), '
            . 'minute INTEGER NOT NULL DEFAULT 0 CHECK (minute BETWEEN 0 AND 59), '
            . 'retention_count INTEGER NOT NULL DEFAULT 4 CHECK (retention_count BETWEEN 1 AND 12), '
            . 'last_due_period TEXT NULL, '
            . 'last_attempt_at TEXT NULL, '
            . 'last_success_at TEXT NULL, '
            . 'last_status TEXT NULL, '
            . 'last_error_code TEXT NULL, '
            . 'last_candidate_id TEXT NULL, '
            . 'last_cleanup_at TEXT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO scheduled_backup_settings (singleton_id, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z')",
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS scheduled_backup_archives ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . 'due_period TEXT NOT NULL, '
            . "status TEXT NOT NULL CHECK (status IN ('creating', 'verified', 'failed')), "
            . 'storage_key TEXT NOT NULL UNIQUE, '
            . 'created_at TEXT NOT NULL, '
            . 'completed_at TEXT NULL, '
            . 'size_bytes INTEGER NOT NULL DEFAULT 0 CHECK (size_bytes >= 0), '
            . 'sha256 TEXT NULL, '
            . 'schema_version TEXT NOT NULL, '
            . 'archive_format_version INTEGER NOT NULL DEFAULT 1 CHECK (archive_format_version = 1), '
            . 'failure_code TEXT NULL, '
            . 'active_downloads INTEGER NOT NULL DEFAULT 0 CHECK (active_downloads >= 0), '
            . 'download_count INTEGER NOT NULL DEFAULT 0 CHECK (download_count >= 0), '
            . 'last_download_at TEXT NULL'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_backup_archives_status ON scheduled_backup_archives (status, created_at DESC)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_backup_archives_period ON scheduled_backup_archives (due_period, status)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_backup_archives_downloads ON scheduled_backup_archives (active_downloads, last_download_at, status)');
    }
}
