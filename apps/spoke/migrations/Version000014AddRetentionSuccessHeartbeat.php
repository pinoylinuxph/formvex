<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000014AddRetentionSuccessHeartbeat implements Migration
{
    public function version(): string
    {
        return '000014';
    }

    public function up(PDO $connection): void
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => 'installation_settings', 'column_name' => 'retention_last_success_at']);

        if ($statement->fetchColumn() === false) {
            $connection->exec('ALTER TABLE installation_settings ADD COLUMN retention_last_success_at TEXT NULL');
        }
    }
}
