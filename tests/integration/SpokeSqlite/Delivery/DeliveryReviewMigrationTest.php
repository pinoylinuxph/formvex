<?php

declare(strict_types=1);

namespace FormvexTests\Integration\SpokeSqlite\Delivery;

use Formvex\Spoke\Migrations\Version000012CreateDeliveryReview;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeliveryReviewMigrationTest extends TestCase
{
    public function testUpgradePreservesLegacyDeliveryDataAndCreatesAttemptCycles(): void
    {
        $connection = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec(
            'CREATE TABLE delivery_jobs ('
            . 'id INTEGER PRIMARY KEY, '
            . 'job_id TEXT NOT NULL UNIQUE, '
            . 'submission_id INTEGER NOT NULL UNIQUE, '
            . "state TEXT NOT NULL DEFAULT 'queued' CHECK (state IN ('queued', 'processing', 'sent', 'failed', 'uncertain')), "
            . 'attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0), '
            . 'due_at TEXT NOT NULL, '
            . 'last_error_code TEXT NULL, '
            . 'created_at TEXT NOT NULL, '
            . 'updated_at TEXT NOT NULL, '
            . 'snapshot_json TEXT NULL, '
            . 'lease_token TEXT NULL, '
            . 'lease_expires_at TEXT NULL, '
            . 'last_outcome TEXT NULL'
            . ')',
        );
        $connection->exec(
            'CREATE TABLE delivery_attempts ('
            . 'id INTEGER PRIMARY KEY, '
            . 'job_id INTEGER NOT NULL, '
            . 'attempt_number INTEGER NOT NULL CHECK (attempt_number BETWEEN 1 AND 6), '
            . "outcome TEXT NOT NULL CHECK (outcome IN ('started', 'accepted', 'temporary_failure', 'permanent_failure', 'uncertain')), "
            . 'error_code TEXT NULL, '
            . 'started_at TEXT NOT NULL, '
            . 'completed_at TEXT NULL, '
            . 'next_due_at TEXT NULL, '
            . 'FOREIGN KEY (job_id) REFERENCES delivery_jobs(id) ON DELETE RESTRICT, '
            . 'UNIQUE (job_id, attempt_number)'
            . ')',
        );
        $connection->exec(
            "INSERT INTO delivery_jobs (id, job_id, submission_id, state, attempt_count, due_at, last_error_code, created_at, updated_at) "
            . "VALUES (1, 'job-1', 1, 'sent', 8, '2026-10-01T00:00:00.000000Z', NULL, '2026-10-01T00:00:00.000000Z', '2026-10-01T00:00:00.000000Z'), "
            . "(2, 'job-2', 2, 'queued', 0, '2026-10-01T00:00:00.000000Z', NULL, '2026-10-01T00:00:00.000000Z', '2026-10-01T00:00:00.000000Z')",
        );
        $connection->exec(
            "INSERT INTO delivery_attempts (id, job_id, attempt_number, outcome, started_at, completed_at) "
            . "VALUES (1, 1, 6, 'accepted', '2026-10-01T00:00:00.000000Z', '2026-10-01T00:00:00.000000Z')",
        );

        $connection->beginTransaction();
        new Version000012CreateDeliveryReview()->up($connection);
        $connection->commit();

        self::assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        self::assertSame(6, (int) $connection->query('SELECT attempt_count FROM delivery_attempt_cycles WHERE delivery_job_id = 1')->fetchColumn());
        self::assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs WHERE active_cycle_id IS NOT NULL')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempts WHERE cycle_id = 1 AND attempt_number = 6')->fetchColumn());
        self::assertSame(0, (int) $connection->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'delivery_attempts_new'")->fetchColumn());
    }
}
