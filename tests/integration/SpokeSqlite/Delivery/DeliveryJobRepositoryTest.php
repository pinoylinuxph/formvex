<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Core\Delivery\DeliveryMessageComposer;
use Formvex\Core\Delivery\DeliveryMessageSnapshot;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Core\Delivery\DeliveryRetryPolicy;
use Formvex\Spoke\Application\Delivery\RunDeliveryWorker;
use Formvex\Spoke\Application\Delivery\RunDeliveryWorkerHandler;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Delivery\Contract\MailTransport;
use Formvex\Spoke\Domain\Delivery\DeliveryTransportResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Formvex\Spoke\Infrastructure\Persistence\PdoDeliveryJobRepository;
use Formvex\Spoke\Infrastructure\Persistence\PdoDeliveryPacingStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoOperationalAlertRepository;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use Formvex\Spoke\Migrations\Version000006CreateSubmissions;
use Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse;
use Formvex\Spoke\Migrations\Version000008CreateEmailDeliveryWorker;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeliveryJobRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-delivery-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
        $this->now = new DateTimeImmutable('2026-09-30T10:00:00.000000Z', new DateTimeZone('UTC'));
        $clock = new TestClock($this->now);
        $store = new PdoInstallationStore(new SqliteMigrationRunner(
            new Version000001CreateInstallationMetadata(),
            new Version000002CreateLocalAdministratorAuth(),
            new Version000003CreateInstallationSettings(),
            new Version000004CreateFormConfiguration(),
            new Version000005CreateFormDiscovery(),
            $clock,
            new Version000006CreateSubmissions(),
            new Version000007CreateSubmissionAbuse(),
            new Version000008CreateEmailDeliveryWorker(),
        ), $clock, new TestIdentifierGenerator());
        $store->initialize($this->paths);
        $this->seedJob();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testClaimIsAtomicAndAcceptedOutcomeClosesTheJob(): void
    {
        $repository = new PdoDeliveryJobRepository();
        $lease = $repository->claimDueJobs($this->paths, $this->now, 10, 'lease-one', $this->now->modify('+2 minutes'));
        self::assertCount(1, $lease);
        self::assertSame(1, $lease[0]->attemptNumber);
        self::assertSame('owner@example.test', $lease[0]->snapshot?->recipient);
        self::assertSame([], $repository->claimDueJobs($this->paths, $this->now, 10, 'lease-two', $this->now->modify('+2 minutes')));
        self::assertTrue($repository->markTransmitting($this->paths, $lease[0], $this->now));
        self::assertTrue($repository->recordOutcome($this->paths, $lease[0], DeliveryOutcomeType::ACCEPTED, '', null, $this->now));

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertSame('sent', $connection->query('SELECT state FROM delivery_jobs')->fetchColumn());
        self::assertSame('accepted', $connection->query('SELECT outcome FROM delivery_attempts')->fetchColumn());
    }

    public function testExpiredProcessingLeaseBecomesUncertainAndIsNotReclaimed(): void
    {
        $repository = new PdoDeliveryJobRepository();
        $expired = $repository->claimDueJobs($this->paths, $this->now, 1, 'lease-old', $this->now->modify('-1 second'));
        self::assertCount(1, $expired);
        self::assertSame([], $repository->claimDueJobs($this->paths, $this->now, 1, 'lease-new', $this->now->modify('+2 minutes')));

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertSame('uncertain', $connection->query('SELECT state FROM delivery_jobs')->fetchColumn());
        self::assertSame('uncertain', $connection->query('SELECT outcome FROM delivery_attempts')->fetchColumn());
    }

    public function testPacingStoreEnforcesOneInstallationWideMinuteBucket(): void
    {
        $pacing = new PdoDeliveryPacingStore();
        self::assertTrue($pacing->tryConsume($this->paths, $this->now, 2));
        self::assertTrue($pacing->tryConsume($this->paths, $this->now, 2));
        self::assertFalse($pacing->tryConsume($this->paths, $this->now, 2));
        self::assertTrue($pacing->tryConsume($this->paths, $this->now->modify('+1 minute'), 2));
    }

    public function testWorkerSendsAcceptedMessageAndRecordsTheOutcome(): void
    {
        $transport = new DeliveryFakeTransport(DeliveryTransportResult::accepted());
        $handler = new RunDeliveryWorkerHandler(
            new SpokeRuntimeConfiguration($this->root),
            new DeliveryStorageResolver($this->paths),
            new PdoInstallationSettingsStore(),
            new PdoDeliveryJobRepository(),
            new PdoDeliveryPacingStore(),
            new PdoOperationalAlertRepository(),
            new DeliveryMessageComposer(),
            $transport,
            new DeliveryRetryPolicy(),
            new TestClock($this->now),
        );
        $result = $handler->handle(new RunDeliveryWorker());

        self::assertTrue($result->succeeded);
        self::assertSame(1, $result->sent);
        self::assertCount(1, $transport->messages);
    }

    public function testWorkerSchedulesTheApprovedTemporaryRetry(): void
    {
        $transport = new DeliveryFakeTransport(DeliveryTransportResult::temporary('smtp_temporary_failure'));
        $handler = new RunDeliveryWorkerHandler(
            new SpokeRuntimeConfiguration($this->root),
            new DeliveryStorageResolver($this->paths),
            new PdoInstallationSettingsStore(),
            new PdoDeliveryJobRepository(),
            new PdoDeliveryPacingStore(),
            new PdoOperationalAlertRepository(),
            new DeliveryMessageComposer(),
            $transport,
            new DeliveryRetryPolicy(),
            new TestClock($this->now),
        );
        $result = $handler->handle(new RunDeliveryWorker(1));
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());

        self::assertSame(1, $result->retried);
        self::assertSame('queued', $connection->query('SELECT state FROM delivery_jobs')->fetchColumn());
        self::assertSame('temporary_failure', $connection->query('SELECT outcome FROM delivery_attempts')->fetchColumn());
        self::assertSame($this->now->modify('+1 minute')->format('Y-m-d\\TH:i:s.u\\Z'), $connection->query('SELECT due_at FROM delivery_jobs')->fetchColumn());
    }

    private function seedJob(): void
    {
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        $timestamp = $this->now->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 'Contact', '{$timestamp}', '{$timestamp}')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, recipient, subject, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, 'owner@example.test', 'Contact request', '{$timestamp}', '{$timestamp}')");
        $connection->exec("INSERT INTO submissions (id, public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, created_at, updated_at) VALUES (1, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 1, 1, 1, '/', 'contactForm', 'owner@example.test', 'Contact request', '{}', 'normal', '{$timestamp}', '{$timestamp}')");
        $snapshot = new DeliveryMessageSnapshot('forms@example.test', 'Forms', 'owner@example.test', 'Contact request', 'normal', []);
        $statement = $connection->prepare("INSERT INTO delivery_jobs (job_id, submission_id, state, attempt_count, due_at, snapshot_json, created_at, updated_at) VALUES ('0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 1, 'queued', 0, :due_at, :snapshot_json, :created_at, :updated_at)");
        $statement->execute(['due_at' => $timestamp, 'snapshot_json' => json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), 'created_at' => $timestamp, 'updated_at' => $timestamp]);
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

final class TestClock implements \Formvex\Spoke\Domain\Installation\Contract\Clock
{
    public function __construct(private readonly DateTimeImmutable $value)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class TestIdentifierGenerator implements \Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return '0195f2b8-7c3a-7f42-8c11-4ac3b865e092';
    }
}

final class DeliveryStorageResolver implements SpokeStorageResolver
{
    public function __construct(private readonly PrivateStoragePaths $paths)
    {
    }

    public function resolve(string $applicationRoot): PrivateStoragePaths
    {
        return $this->paths;
    }

    public function assertOperatorOwns(PrivateStoragePaths $paths): void
    {
    }
}

final class DeliveryFakeTransport implements MailTransport
{
    /** @var list<\Formvex\Core\Delivery\DeliveryMessage> */
    public array $messages = [];

    public function __construct(private readonly DeliveryTransportResult $result)
    {
    }

    public function send(PrivateStoragePaths $paths, \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings $settings, \Formvex\Core\Delivery\DeliveryMessage $message): DeliveryTransportResult
    {
        $this->messages[] = $message;

        return $this->result;
    }
}
