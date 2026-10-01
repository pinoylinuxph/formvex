<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewFailure;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewQuery;
use Formvex\Spoke\Domain\Delivery\DeliveryWarning;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoDeliveryReviewRepository;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
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
use PDO;
use PHPUnit\Framework\TestCase;

final class DeliveryReviewRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-delivery-review-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths(
            $this->root,
            $this->root . '/database',
            $this->root . '/secrets',
            $this->root . '/logs',
            $this->root . '/exports',
            $this->root . '/diagnostics',
            $this->root . '/backups/scheduled',
            $this->root . '/backups/manual',
            $this->root . '/backups/temporary',
            $this->root . '/runtime',
        );
        $this->now = new DateTimeImmutable('2026-09-30T10:00:00.000000Z', new DateTimeZone('UTC'));
        $clock = new DeliveryReviewClock($this->now);
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
            ),
            $clock,
            new DeliveryReviewIdentifierGenerator(),
        );
        $store->initialize($this->paths);
        $this->seedFormAndSubmission();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testListAndDetailsExposeSafeDeliveryMetadataAndAuditHistory(): void
    {
        $repository = new PdoDeliveryReviewRepository();
        $query = DeliveryReviewQuery::fromInput(['state' => 'failed', 'outcome' => 'permanent_failure']);
        $result = $repository->list($this->paths, $query);
        $details = $repository->find($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092');

        self::assertCount(1, $result->items);
        self::assertSame('o••••@e••••••.test', $result->items[0]->recipientMasked);
        self::assertNotSame('owner@example.test', $result->items[0]->recipientMasked);
        self::assertNotNull($details);
        self::assertTrue($details->resendAllowed);
        self::assertSame('smtp_authentication_failed', $details->lastErrorCode);
        self::assertStringContainsString('rejected the configured credentials', $details->lastErrorMessage);
        self::assertCount(1, $details->attempts);
        self::assertCount(1, $details->auditEvents);
        self::assertSame('Resend rejected', $details->auditEvents[0]['event']);
    }

    public function testFailedResendCreatesAQueuedManualCycleAndAuditEvent(): void
    {
        $repository = new PdoDeliveryReviewRepository();
        $result = $repository->resend($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', false, $this->now);
        $connection = $this->connection();
        $details = $repository->find($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092');

        self::assertTrue($result->changed);
        self::assertSame(2, $result->cycleNumber);
        self::assertSame('queued', $connection->query('SELECT state FROM delivery_jobs')->fetchColumn());
        self::assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        self::assertSame('success', $connection->query("SELECT outcome FROM audit_events WHERE event_name = 'spoke.delivery_review.resend_queued'")->fetchColumn());
        self::assertNotNull($details);
        self::assertFalse($details->resendAllowed);
        self::assertSame(1, $details->manualCycleCount);
    }

    public function testUncertainResendRequiresConfirmationAndThenPreservesHistory(): void
    {
        $connection = $this->connection();
        $this->setJobState($connection, 'uncertain', 'uncertain', 'delivery_uncertain');
        $repository = new PdoDeliveryReviewRepository();

        try {
            $repository->resend($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', false, $this->now);
            self::fail('An uncertain resend without confirmation must be rejected.');
        } catch (DeliveryReviewFailure $failure) {
            self::assertSame('uncertain_confirmation_required', $failure->failureCode);
        }

        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        $result = $repository->resend($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', true, $this->now);

        self::assertSame(2, $result->cycleNumber);
        self::assertSame(2, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        self::assertSame('spoke.delivery_review.uncertain_resend_confirmed', $connection->query("SELECT event_name FROM audit_events WHERE event_name = 'spoke.delivery_review.uncertain_resend_confirmed'")->fetchColumn());
    }

    public function testManualResendStopsAfterThreeCycles(): void
    {
        $connection = $this->connection();
        $timestamp = $this->now->format('Y-m-d\\TH:i:s.u\\Z');
        for ($cycleNumber = 2; $cycleNumber <= 4; $cycleNumber++) {
            $statement = $connection->prepare(
                "INSERT INTO delivery_attempt_cycles (delivery_job_id, cycle_number, origin, state, attempt_count, created_at, updated_at) VALUES (1, :cycle_number, 'manual', 'failed', 6, :created_at, :updated_at)",
            );
            $statement->execute(['cycle_number' => $cycleNumber, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        }
        $repository = new PdoDeliveryReviewRepository();

        try {
            $repository->resend($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', false, $this->now);
            self::fail('A fourth manual resend must be rejected.');
        } catch (DeliveryReviewFailure $failure) {
            self::assertSame('resend_limit_reached', $failure->failureCode);
        }

        self::assertSame(4, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        self::assertSame('resend_limit_reached', $connection->query("SELECT outcome FROM audit_events WHERE event_name = 'spoke.delivery_review.resend_rejected' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function testQueuedProcessingAndSentStatesRejectManualResend(): void
    {
        $repository = new PdoDeliveryReviewRepository();
        $connection = $this->connection();

        foreach (['queued', 'processing', 'sent'] as $state) {
            $this->setJobState($connection, $state, $state === 'sent' ? 'accepted' : 'temporary_failure', null);

            try {
                $repository->resend($this->paths, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', false, $this->now);
                self::fail('A ' . $state . ' delivery must reject manual resend.');
            } catch (DeliveryReviewFailure $failure) {
                self::assertSame('not_eligible', $failure->failureCode);
            }

            self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_attempt_cycles')->fetchColumn());
        }
    }

    public function testWarningsDistinguishSmtpSchedulerAndQueueHealth(): void
    {
        $repository = new PdoDeliveryReviewRepository();
        $warnings = $repository->warnings($this->paths, $this->now);
        self::assertContains('SMTP delivery is not ready', array_map(static fn (DeliveryWarning $warning): string => $warning->title, $warnings));
        self::assertContains('Scheduler status is not confirmed', array_map(static fn (DeliveryWarning $warning): string => $warning->title, $warnings));

        $connection = $this->connection();
        $sixMinutesAgo = $this->now->modify('-6 minutes')->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->exec("UPDATE delivery_worker_heartbeat SET last_success_at = '{$sixMinutesAgo}', updated_at = '{$sixMinutesAgo}' WHERE singleton_id = 1");
        $connection->exec("UPDATE delivery_jobs SET state = 'queued', due_at = '{$sixMinutesAgo}' WHERE id = 1");
        $warnings = $repository->warnings($this->paths, $this->now);
        $warningTitles = array_map(static fn (DeliveryWarning $warning): string => $warning->title, $warnings);
        self::assertContains('Scheduler warning', $warningTitles);
        self::assertContains('Delivery queue is delayed', $warningTitles);

        $sixteenMinutesAgo = $this->now->modify('-16 minutes')->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->exec("UPDATE delivery_worker_heartbeat SET last_success_at = '{$sixteenMinutesAgo}', updated_at = '{$sixteenMinutesAgo}' WHERE singleton_id = 1");
        $connection->exec("UPDATE delivery_jobs SET due_at = '{$sixteenMinutesAgo}' WHERE id = 1");
        $warnings = $repository->warnings($this->paths, $this->now);
        $criticalTitles = array_map(static fn (DeliveryWarning $warning): string => $warning->title, $warnings);
        self::assertCount(2, array_filter($warnings, static fn (DeliveryWarning $warning): bool => $warning->severity === 'critical'));
        self::assertContains('Scheduler warning', $criticalTitles);
        self::assertContains('Delivery queue is delayed', $criticalTitles);
    }

    private function seedFormAndSubmission(): void
    {
        $connection = $this->connection();
        $timestamp = $this->now->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 'Contact', '{$timestamp}', '{$timestamp}')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, recipient, subject, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, 'owner@example.test', 'Contact request', '{$timestamp}', '{$timestamp}')");
        $connection->exec("INSERT INTO submissions (id, public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, created_at, updated_at) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 1, 1, 1, '/', 'contactForm', 'owner@example.test', 'Contact request', '{}', 'normal', '{$timestamp}', '{$timestamp}')");
        $statement = $connection->prepare(
            "INSERT INTO delivery_jobs (id, job_id, submission_id, state, attempt_count, due_at, last_error_code, last_outcome, created_at, updated_at) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 1, 'failed', 6, :due_at, 'smtp_authentication_failed', 'permanent_failure', :created_at, :updated_at)",
        );
        $statement->execute(['due_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        $cycle = $connection->prepare(
            "INSERT INTO delivery_attempt_cycles (id, delivery_job_id, cycle_number, origin, state, attempt_count, last_error_code, created_at, updated_at) VALUES (1, 1, 1, 'automatic', 'failed', 6, 'smtp_authentication_failed', :created_at, :updated_at)",
        );
        $cycle->execute(['created_at' => $timestamp, 'updated_at' => $timestamp]);
        $connection->exec('UPDATE delivery_jobs SET active_cycle_id = 1 WHERE id = 1');
        $attempt = $connection->prepare(
            "INSERT INTO delivery_attempts (id, job_id, cycle_id, attempt_number, outcome, error_code, started_at, completed_at) VALUES (1, 1, 1, 6, 'permanent_failure', 'smtp_authentication_failed', :started_at, :completed_at)",
        );
        $attempt->execute(['started_at' => $timestamp, 'completed_at' => $timestamp]);
        $audit = $connection->prepare(
            "INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES ('spoke.delivery_review.resend_rejected', 'not_eligible', :occurred_at, 'delivery', :public_id)",
        );
        $audit->execute(['occurred_at' => $timestamp, 'public_id' => '0195f2b8-7c3a-7f42-8c11-4ac3b865e092']);
    }

    private function setJobState(PDO $connection, string $state, string $outcome, ?string $error): void
    {
        $statement = $connection->prepare('UPDATE delivery_jobs SET state = :state, last_outcome = :outcome, last_error_code = :error WHERE id = 1');
        $statement->execute(['state' => $state, 'outcome' => $outcome, 'error' => $error]);
        $cycle = $connection->prepare('UPDATE delivery_attempt_cycles SET state = :state, last_error_code = :error WHERE id = 1');
        $cycle->execute(['state' => $state, 'error' => $error]);
    }

    private function connection(): PDO
    {
        $connection = new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
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

final class DeliveryReviewClock implements \Formvex\Spoke\Domain\Installation\Contract\Clock
{
    public function __construct(private readonly DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class DeliveryReviewIdentifierGenerator implements \Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return '0195f2b8-7c3a-7f42-8c11-4ac3b865e092';
    }
}
