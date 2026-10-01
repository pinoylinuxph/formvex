<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000010CreateInstallationBranding implements Migration
{
    public function version(): string
    {
        return '000010';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS branding_settings ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "brand_name TEXT NOT NULL DEFAULT 'Noname', "
            . "slogan TEXT NOT NULL DEFAULT '', "
            . 'slogan_visible INTEGER NOT NULL DEFAULT 0 CHECK (slogan_visible IN (0, 1)), '
            . 'logo_filename TEXT NULL, logo_media_type TEXT NULL, logo_sha256 TEXT NULL, logo_bytes INTEGER NULL, logo_width INTEGER NULL, logo_height INTEGER NULL, '
            . 'favicon_filename TEXT NULL, favicon_media_type TEXT NULL, favicon_sha256 TEXT NULL, favicon_bytes INTEGER NULL, favicon_width INTEGER NULL, favicon_height INTEGER NULL, '
            . 'asset_revision INTEGER NOT NULL DEFAULT 1 CHECK (asset_revision >= 1), '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO branding_settings (singleton_id, created_at, updated_at) VALUES (1, '1970-01-01T00:00:00.000000Z', '1970-01-01T00:00:00.000000Z')",
        );
    }
}
