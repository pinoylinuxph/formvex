<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\DeliveryControlFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoDeliveryControlRepository;
use Formvex\Spoke\Infrastructure\Persistence\PdoDeliveryJobRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeliveryControlRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-delivery-control-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
        $this->now = new DateTimeImmutable('2026-10-04T10:00:00.000000Z', new DateTimeZone('UTC'));
        $connection = $this->connection();
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT \'{}\')');
        $connection->exec("CREATE TABLE delivery_control (singleton_id INTEGER PRIMARY KEY CHECK (singleton_id = 1), state TEXT NOT NULL CHECK (state IN ('running', 'paused')), changed_at TEXT NOT NULL, changed_by TEXT NULL)");
        $connection->exec("INSERT INTO delivery_control (singleton_id, state, changed_at) VALUES (1, 'running', '1970-01-01T00:00:00.000000Z')");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPauseAndResumeAreAtomicAndIdempotent(): void
    {
        $repository = new PdoDeliveryControlRepository();

        self::assertFalse($repository->status($this->paths)->isPaused());
        self::assertTrue($repository->pause($this->paths, 'admin', $this->now)->isPaused());
        self::assertSame('paused', $this->connection()->query('SELECT state FROM delivery_control')->fetchColumn());
        self::assertSame('spoke.delivery_paused', $this->connection()->query('SELECT event_name FROM audit_events')->fetchColumn());

        try {
            $repository->pause($this->paths, 'admin', $this->now);
            self::fail('A repeated pause must return a safe current-state failure.');
        } catch (DeliveryControlFailure $failure) {
            self::assertSame('already_paused', $failure->failureCode);
        }

        self::assertFalse($repository->resume($this->paths, 'admin', $this->now)->isPaused());
        self::assertSame('running', $this->connection()->query('SELECT state FROM delivery_control')->fetchColumn());
        self::assertSame(2, (int) $this->connection()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn());
    }

    public function testFailedAuditRollsBackTheStateTransition(): void
    {
        $this->connection()->exec("CREATE TRIGGER fail_delivery_control_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");

        try {
            new PdoDeliveryControlRepository()->pause($this->paths, 'admin', $this->now);
            self::fail('A failed audit insert must fail the state transition.');
        } catch (DeliveryControlFailure $failure) {
            self::assertSame('transition_failed', $failure->failureCode);
        }

        self::assertSame('running', $this->connection()->query('SELECT state FROM delivery_control')->fetchColumn());
        self::assertSame(0, (int) $this->connection()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn());
    }

    public function testPausedWorkerClaimsQualificationButNotContactDelivery(): void
    {
        $connection = $this->connection();
        $connection->exec('CREATE TABLE submissions (id INTEGER PRIMARY KEY, is_qualification_test INTEGER NOT NULL DEFAULT 0)');
        $connection->exec('CREATE TABLE delivery_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id INTEGER NOT NULL, attempt_number INTEGER NOT NULL, outcome TEXT NOT NULL, started_at TEXT NOT NULL)');
        $connection->exec('CREATE TABLE delivery_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, job_id TEXT NOT NULL UNIQUE, submission_id INTEGER NOT NULL, state TEXT NOT NULL, attempt_count INTEGER NOT NULL, due_at TEXT NOT NULL, snapshot_json TEXT NULL, lease_token TEXT NULL, lease_expires_at TEXT NULL, updated_at TEXT NOT NULL)');
        $timestamp = $this->now->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->exec('INSERT INTO submissions (id, is_qualification_test) VALUES (1, 0), (2, 1)');
        $statement = $connection->prepare("INSERT INTO delivery_jobs (job_id, submission_id, state, attempt_count, due_at, updated_at) VALUES (:job_id, :submission_id, 'queued', 0, :due_at, :updated_at)");
        foreach ([['contact-job', 1], ['qualification-job', 2]] as [$jobId, $submissionId]) {
            $statement->execute(['job_id' => $jobId, 'submission_id' => $submissionId, 'due_at' => $timestamp, 'updated_at' => $timestamp]);
        }
        new PdoDeliveryControlRepository()->pause($this->paths, 'admin', $this->now);

        $claims = new PdoDeliveryJobRepository()->claimDueJobs($this->paths, $this->now, 10, 'lease-one', $this->now->modify('+2 minutes'));

        self::assertCount(1, $claims);
        self::assertSame('qualification-job', $claims[0]->jobId);
        self::assertSame('queued', $connection->query("SELECT state FROM delivery_jobs WHERE job_id = 'contact-job'")->fetchColumn());
        self::assertSame('processing', $connection->query("SELECT state FROM delivery_jobs WHERE job_id = 'qualification-job'")->fetchColumn());
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
