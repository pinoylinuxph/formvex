<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000017CreateDeliveryControl implements Migration
{
    public function version(): string
    {
        return '000017';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS delivery_control ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "state TEXT NOT NULL DEFAULT 'running' CHECK (state IN ('running', 'paused')), "
            . 'changed_at TEXT NOT NULL, '
            . 'changed_by TEXT NULL'
            . ')',
        );
        $connection->exec(
            "INSERT OR IGNORE INTO delivery_control (singleton_id, state, changed_at, changed_by) VALUES (1, 'running', '1970-01-01T00:00:00.000000Z', NULL)",
        );
    }
}
