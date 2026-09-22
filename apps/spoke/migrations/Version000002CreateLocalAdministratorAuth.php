<?php

declare(strict_types=1);

namespace Formvex\Spoke\Migrations;

use Formvex\Spoke\Infrastructure\Persistence\Migration;
use PDO;

final class Version000002CreateLocalAdministratorAuth implements Migration
{
    public function version(): string
    {
        return '000002';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS local_administrators ('
            . 'singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), '
            . "login_identifier TEXT NOT NULL UNIQUE CHECK (login_identifier = 'admin'), "
            . 'password_hash TEXT NOT NULL, '
            . 'must_change_password INTEGER NOT NULL CHECK (must_change_password IN (0, 1)), '
            . 'session_invalidation_generation INTEGER NOT NULL CHECK (session_invalidation_generation >= 1), '
            . 'created_at TEXT NOT NULL, '
            . 'password_changed_at TEXT NULL, '
            . 'last_login_at TEXT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS admin_sessions ('
            . 'session_id_hash TEXT PRIMARY KEY NOT NULL, '
            . 'administrator_singleton_id INTEGER NOT NULL DEFAULT 1, '
            . 'csrf_token_hash TEXT NOT NULL, '
            . 'session_invalidation_generation INTEGER NOT NULL CHECK (session_invalidation_generation >= 1), '
            . 'created_at TEXT NOT NULL, '
            . 'last_activity_at TEXT NOT NULL, '
            . 'expires_at TEXT NOT NULL, '
            . 'revoked_at TEXT NULL, '
            . 'FOREIGN KEY (administrator_singleton_id) REFERENCES local_administrators(singleton_id)'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires_at ON admin_sessions (expires_at)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS admin_login_throttles ('
            . 'throttle_key_hash TEXT PRIMARY KEY NOT NULL, '
            . 'failed_attempts INTEGER NOT NULL CHECK (failed_attempts >= 0), '
            . 'window_started_at TEXT NOT NULL, '
            . 'cooldown_until TEXT NULL, '
            . 'updated_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_admin_login_throttles_updated_at '
            . 'ON admin_login_throttles (updated_at)',
        );
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS audit_events ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'event_name TEXT NOT NULL, '
            . 'outcome TEXT NOT NULL, '
            . 'occurred_at TEXT NOT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE INDEX IF NOT EXISTS idx_audit_events_occurred_at ON audit_events (occurred_at)',
        );
    }
}
