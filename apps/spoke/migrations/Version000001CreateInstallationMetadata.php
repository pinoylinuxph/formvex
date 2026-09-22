<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000001CreateInstallationMetadata implements Migration
{
    public function version(): string
    {
        return '000001';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS installation_metadata ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . 'installation_id TEXT NOT NULL UNIQUE, '
            . 'initialized_at TEXT NOT NULL, '
            . 'schema_version TEXT NOT NULL'
            . ')',
        );
    }
}
