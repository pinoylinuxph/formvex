<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoRetentionRepository;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use Formvex\Spoke\Migrations\Version000006CreateSubmissions;
use Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse;
use Formvex\Spoke\Migrations\Version000008CreateEmailDeliveryWorker;
use Formvex\Spoke\Migrations\Version000009CreateFormActivation;
use Formvex\Spoke\Migrations\Version000010CreateInstallationBranding;
use Formvex\Spoke\Migrations\Version000011CreateSubmissionReview;
use Formvex\Spoke\Migrations\Version000012CreateDeliveryReview;
use Formvex\Spoke\Migrations\Version000013CreateRetentionAndCleanup;
use PDO;
use PHPUnit\Framework\TestCase;

final class RetentionRepositoryTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-retention-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot . '/database',
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot . '/logs',
            $this->temporaryRoot . '/exports',
            $this->temporaryRoot . '/diagnostics',
            $this->temporaryRoot . '/backups/scheduled',
            $this->temporaryRoot . '/backups/manual',
            $this->temporaryRoot . '/backups/temporary',
            $this->temporaryRoot . '/runtime',
        );
        $this->now = new DateTimeImmutable('2026-09-22T12:34:56.123456Z', new DateTimeZone('UTC'));
        $clock = new FixedClock();
        $store = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $clock,
                new Version000006CreateSubmissions(),
                new Version000007CreateSubmissionAbuse(),
                new Version000008CreateEmailDeliveryWorker(),
                new Version000009CreateFormActivation(),
                new Version000010CreateInstallationBranding(),
                new Version000011CreateSubmissionReview(),
                new Version000012CreateDeliveryReview(),
                new Version000013CreateRetentionAndCleanup(),
            ),
            $clock,
            new FixedIdentifierGenerator(),
        );
        $store->initialize($this->paths);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testCleanupDeletesExpiredTerminalRecordsAndDefersActiveDelivery(): void
    {
        $connection = $this->connection();
        $this->insertForm($connection);
        $this->insertSubmission($connection, 1, 'old-sent', 'accepted', 'sent', '2026-01-01T00:00:00.000000Z', '2026-01-01T00:00:01.000000Z');
        $this->insertSubmission($connection, 2, 'old-uncertain', 'accepted', 'uncertain', '2026-01-01T00:00:00.000000Z', '2026-01-01T00:00:01.000000Z');
        $this->insertSubmission($connection, 3, 'old-trash', 'trashed', 'failed', '2026-01-01T00:00:00.000000Z', null, '2026-01-02T00:00:00.000000Z');
        $this->insertSubmission($connection, 4, 'active-delivery', 'trashed', 'processing', '2026-01-01T00:00:00.000000Z', null, '2026-01-02T00:00:00.000000Z');
        $this->insertSubmission($connection, 5, 'recently-restored', 'handled', 'sent', '2026-01-01T00:00:00.000000Z', '2026-01-01T00:00:01.000000Z');
        $connection->exec("UPDATE submissions SET restored_at = '2026-09-01T00:00:00.000000Z' WHERE id = 5");
        $connection->exec("INSERT INTO submission_attempts (public_form_id, attempt_id, submission_id, payload_hash, receipt_id, accepted_at, expires_at) VALUES ('form-1', 'attempt-1', 4, 'hash', 'receipt', '2026-01-01T00:00:00.000000Z', '2026-01-02T00:00:00.000000Z')");

        $result = new PdoRetentionRepository()->cleanup($this->paths, InstallationSettings::defaults(), $this->now, 100);

        self::assertTrue($result->succeeded);
        self::assertSame(3, $result->deleted);
        self::assertSame(2, $result->deferred);
        self::assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame('2026-10-01T00:00:00.000000Z', $connection->query('SELECT recovery_deadline FROM submissions WHERE id = 5')->fetchColumn());
        self::assertSame('completed', $connection->query('SELECT retention_last_status FROM installation_settings')->fetchColumn());
    }

    private function insertForm(PDO $connection): void
    {
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, 'form-1', 'Test form', '2025-01-01T00:00:00.000000Z', '2025-01-01T00:00:00.000000Z')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, '2025-01-01T00:00:00.000000Z', '2025-01-01T00:00:00.000000Z')");
    }

    private function insertSubmission(PDO $connection, int $id, string $publicId, string $state, string $deliveryState, string $createdAt, ?string $acceptedAt, ?string $trashedAt = null): void
    {
        $statement = $connection->prepare(
            "INSERT INTO submissions (id, public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, state, created_at, updated_at, trashed_at) VALUES (:id, :public_id, 1, 1, 1, '/', 'contact', 'owner@example.com', 'Test', '{}', 'normal', :state, :created_at, :created_at, :trashed_at)",
        );
        $statement->execute(['id' => $id, 'public_id' => $publicId, 'state' => $state, 'created_at' => $createdAt, 'trashed_at' => $trashedAt]);
        $connection->prepare("INSERT INTO delivery_jobs (id, job_id, submission_id, state, attempt_count, due_at, created_at, updated_at) VALUES (:id, :job_id, :submission_id, :state, 1, :created_at, :created_at, :created_at)")->execute(['id' => $id, 'job_id' => 'job-' . $id, 'submission_id' => $id, 'state' => $deliveryState, 'created_at' => $createdAt]);
        $connection->prepare("INSERT INTO delivery_attempt_cycles (id, delivery_job_id, cycle_number, origin, state, attempt_count, created_at, updated_at) VALUES (:id, :job_id, 1, 'automatic', :state, 1, :created_at, :created_at)")->execute(['id' => $id, 'job_id' => $id, 'state' => $deliveryState, 'created_at' => $createdAt]);
        if ($deliveryState === 'uncertain') {
            $connection->prepare("INSERT INTO delivery_attempts (job_id, cycle_id, attempt_number, outcome, started_at, completed_at) VALUES (:job_id, :cycle_id, 1, 'uncertain', :started_at, :completed_at)")->execute(['job_id' => $id, 'cycle_id' => $id, 'started_at' => $createdAt, 'completed_at' => $acceptedAt ?? $createdAt]);
        } elseif ($acceptedAt !== null) {
            $connection->prepare("INSERT INTO delivery_attempts (job_id, cycle_id, attempt_number, outcome, started_at, completed_at) VALUES (:job_id, :cycle_id, 1, 'accepted', :started_at, :completed_at)")->execute(['job_id' => $id, 'cycle_id' => $id, 'started_at' => $createdAt, 'completed_at' => $acceptedAt]);
        } else {
            $connection->prepare("INSERT INTO delivery_attempts (job_id, cycle_id, attempt_number, outcome, started_at, completed_at) VALUES (:job_id, :cycle_id, 1, 'uncertain', :started_at, :completed_at)")->execute(['job_id' => $id, 'cycle_id' => $id, 'started_at' => $createdAt, 'completed_at' => $createdAt]);
        }
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
