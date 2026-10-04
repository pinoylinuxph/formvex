<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000021CreateDiagnosticReports implements Migration
{
    public function version(): string
    {
        return '000021';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS diagnostic_reports ('
            . 'public_id TEXT PRIMARY KEY NOT NULL, '
            . 'actor TEXT NOT NULL, '
            . 'storage_key TEXT NOT NULL UNIQUE, '
            . 'generated_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'size_bytes INTEGER NOT NULL CHECK (size_bytes >= 0 AND size_bytes <= 131072), '
            . "status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'expired')), "
            . 'created_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_diagnostic_reports_expiry ON diagnostic_reports (status, expires_at)');
        $connection->exec('CREATE INDEX IF NOT EXISTS idx_diagnostic_reports_latest ON diagnostic_reports (status, generated_at DESC)');
    }
}
