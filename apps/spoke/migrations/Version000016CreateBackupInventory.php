<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000016CreateBackupInventory implements Migration
{
    public function version(): string
    {
        return '000016';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS backup_archives ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'public_id TEXT NOT NULL UNIQUE, '
            . "kind TEXT NOT NULL CHECK (kind IN ('manual', 'pre_upgrade')), "
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
            . 'download_count INTEGER NOT NULL DEFAULT 0 CHECK (download_count >= 0)'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_backup_archives_status ON backup_archives (status, created_at DESC)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_backup_archives_kind ON backup_archives (kind, status, created_at DESC)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_backup_archives_downloads ON backup_archives (active_downloads, status)');
    }
}
