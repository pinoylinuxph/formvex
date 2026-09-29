<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000005CreateFormDiscovery implements Migration
{
    public function version(): string
    {
        return '000005';
    }

    public function up(PDO $connection): void
    {
        $columns = $connection->query('PRAGMA table_info(installation_settings)')->fetchAll(PDO::FETCH_ASSOC);
        $hasPayloadLimit = false;

        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'discovery_payload_limit_bytes') {
                $hasPayloadLimit = true;
                break;
            }
        }

        if (!$hasPayloadLimit) {
            $connection->exec(
                'ALTER TABLE installation_settings ADD COLUMN discovery_payload_limit_bytes INTEGER NOT NULL DEFAULT 131072 CHECK (discovery_payload_limit_bytes BETWEEN 131072 AND 1048576)',
            );
        }

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_discovery_capabilities ('
            . 'id INTEGER PRIMARY KEY, '
            . 'capability_id TEXT NOT NULL UNIQUE, '
            . 'token_hash TEXT NOT NULL UNIQUE, '
            . 'session_hash TEXT NOT NULL, '
            . 'host TEXT NOT NULL, '
            . 'path TEXT NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 2), '
            . 'metadata_accepted INTEGER NOT NULL DEFAULT 0 CHECK (metadata_accepted IN (0, 1)), '
            . 'redeemed_at TEXT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_discovery_capabilities_session '
            . 'ON form_discovery_capabilities (session_hash, expires_at)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS form_discovery_candidates ('
            . 'id INTEGER PRIMARY KEY, '
            . 'candidate_id TEXT NOT NULL UNIQUE, '
            . 'capability_id INTEGER NOT NULL, '
            . 'session_hash TEXT NOT NULL, '
            . 'host TEXT NOT NULL, '
            . 'path TEXT NOT NULL, '
            . 'schema_version INTEGER NOT NULL CHECK (schema_version = 1), '
            . "status TEXT NOT NULL CHECK (status IN ('pending', 'applied', 'discarded')), "
            . 'payload_json TEXT NOT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'FOREIGN KEY (capability_id) REFERENCES form_discovery_capabilities(id) ON DELETE CASCADE'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_discovery_candidates_session '
            . 'ON form_discovery_candidates (session_hash, status, expires_at)',
        );
    }
}
