<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Contracts\V1\Submission\SubmissionFieldShape;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Core\Submission\CanonicalSubmissionHasher;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\Submission\SubmissionService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldChoice;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormConfigurationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoSubmissionStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SubmissionServiceTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    private SubmissionService $service;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-submission-' . bin2hex(random_bytes(8));
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
        $clock = new FixedClock();
        $installationStore = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new \Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata(),
                new \Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth(),
                new \Formvex\Spoke\Migrations\Version000003CreateInstallationSettings(),
                new \Formvex\Spoke\Migrations\Version000004CreateFormConfiguration(),
                new \Formvex\Spoke\Migrations\Version000005CreateFormDiscovery(),
                $clock,
                new \Formvex\Spoke\Migrations\Version000006CreateSubmissions(),
            ),
            $clock,
            new FixedIdentifierGenerator(),
        );
        $installationStore->initialize($this->paths);
        $settingsStore = new PdoInstallationSettingsStore();
        $settingsStore->save(
            $this->paths,
            \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings::defaults()->withIdentity('Logoslab', 'logoslab.xyz', 'www.logoslab.xyz', 'owner@example.test'),
            $clock->now(),
        );
        $formService = new FormConfigurationService(
            new SubmissionStorageResolver($this->paths),
            new PdoFormConfigurationStore(),
            $settingsStore,
            new FixedIdentifierGenerator(),
            $clock,
        );
        $draft = $formService->create($this->temporaryRoot, new FormConfigurationDraftData(
            'Contact form',
            PageIdentity::fromInput('logoslab.xyz', '/', 'contactForm'),
            'owner@example.test',
            'Contact message',
            [
                new FormFieldDefinition('name', 'name', 'text', 'Name', 'name', 0, true, 120),
                new FormFieldDefinition('email', 'email', 'email', 'Email', 'email', 1, true, 254),
                new FormFieldDefinition('sector', 'sector', 'select', 'Sector', 'sector', 2, false, 40, [
                    new FormFieldChoice('engineering', 'Engineering'),
                    new FormFieldChoice('support', 'Support'),
                ]),
            ],
        ));
        $published = $formService->publish($this->temporaryRoot, $draft->draft->publicId, 1);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        $connection->prepare("UPDATE form_configuration_versions SET state = 'active' WHERE id = :id")->execute(['id' => $this->versionId($connection, $draft->draft->publicId, $published->versionNumber)]);
        $this->service = new SubmissionService(
            new SubmissionStorageResolver($this->paths),
            new PdoFormConfigurationStore(),
            new PdoSubmissionStore(),
            new CanonicalSubmissionHasher(),
            new FixedIdentifierGenerator(),
            $clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testMatchingRetryReturnsTheOriginalReceiptAndCreatesOneDurableAcceptance(): void
    {
        $request = $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']);
        $first = $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
        $second = $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());

        self::assertSame($first->receiptId, $second->receiptId);
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());
        self::assertSame('queued', $connection->query('SELECT state FROM delivery_jobs')->fetchColumn());
    }

    public function testChangedPayloadUnderTheSameAttemptIsRejectedWithoutMutation(): void
    {
        $request = $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']);
        $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);

        try {
            $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $this->request(['name' => 'Grace Hopper', 'email' => 'ada@example.test', 'sector' => 'engineering']));
            self::fail('A changed payload must not be accepted under the same attempt reference.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('attempt_conflict', $failure->failureCode);
        }

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
    }

    public function testInvalidChoiceAndRequiredValuesAreRejectedBeforePersistence(): void
    {
        try {
            $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $this->request(['name' => '', 'email' => 'not-an-email', 'sector' => 'billing']));
            self::fail('Invalid field values must be rejected.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('field_validation_failed', $failure->failureCode);
            self::assertSame('required', $failure->fieldErrors['name']['code']);
            self::assertSame('email_invalid', $failure->fieldErrors['email']['code']);
            self::assertSame('choice_invalid', $failure->fieldErrors['sector']['code']);
        }

        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
    }

    public function testExpiredAttemptEvidenceRequiresASeparateAttempt(): void
    {
        $request = $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']);
        $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        $connection->exec("UPDATE submission_attempts SET expires_at = '2026-09-22T12:34:55.123456Z'");

        try {
            $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
            self::fail('An expired attempt must not silently become a new submission.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('attempt_expired', $failure->failureCode);
        }

        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
    }

    public function testMismatchedBrowserShapeIsRejectedBeforePersistence(): void
    {
        $request = new SubmissionRequest(
            1,
            '/',
            'contactForm',
            1,
            '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering'],
            [new SubmissionFieldShape('name', 'email')],
        );

        try {
            $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
            self::fail('A mismatched browser shape must be rejected.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('submission_shape_invalid', $failure->failureCode);
        }
    }

    public function testDeliveryJobFailureRollsBackTheSubmissionAndAttempt(): void
    {
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        $connection->exec("CREATE TRIGGER fail_delivery_job INSERT ON delivery_jobs BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");

        try {
            $this->service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']));
            self::fail('A delivery-job write failure must not return an accepted result.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('storage_unavailable', $failure->failureCode);
        }

        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());
    }

    public function testConcurrentIdenticalRequestsReturnOneReceiptAndOneDurableAcceptance(): void
    {
        $results = $this->runConcurrent([
            $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']),
            $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']),
        ]);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());

        self::assertSame(['accepted', 'accepted'], array_column($results, 'status'));
        self::assertSame($results[0]['receipt_id'], $results[1]['receipt_id']);
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());
    }

    public function testConcurrentConflictingRequestsAcceptOnlyOnePayload(): void
    {
        $results = $this->runConcurrent([
            $this->request(['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'sector' => 'engineering']),
            $this->request(['name' => 'Grace Hopper', 'email' => 'ada@example.test', 'sector' => 'engineering']),
        ]);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());

        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['accepted', 'attempt_conflict'], $statuses);
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submissions')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());

        $storedFields = json_decode((string) $connection->query('SELECT fields_json FROM submissions')->fetchColumn(), true, 4, JSON_THROW_ON_ERROR);
        self::assertContains($storedFields['name'] ?? null, ['Ada Lovelace', 'Grace Hopper']);
    }

    /** @param array<string, string> $fields */
    private function request(array $fields): SubmissionRequest
    {
        return new SubmissionRequest(
            1,
            '/',
            'contactForm',
            1,
            '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            $fields,
            [
                new SubmissionFieldShape('name', 'text'),
                new SubmissionFieldShape('email', 'email'),
                new SubmissionFieldShape('sector', 'select'),
            ],
        );
    }

    private function versionId(PDO $connection, string $publicId, int $versionNumber): int
    {
        $statement = $connection->prepare('SELECT v.id FROM form_configuration_versions v INNER JOIN form_configurations f ON f.id = v.form_id WHERE f.public_id = :public_id AND v.version_number = :version_number');
        $statement->execute(['public_id' => $publicId, 'version_number' => $versionNumber]);

        return (int) $statement->fetchColumn();
    }

    /** @param array{0: SubmissionRequest, 1: SubmissionRequest} $requests @return list<array{status: string, receipt_id?: string, error?: string}> */
    private function runConcurrent(array $requests): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('The concurrent SQLite test requires the pcntl extension.');
        }

        $barrier = $this->temporaryRoot . '/runtime/concurrent-start';
        $readyFiles = [
            $this->temporaryRoot . '/runtime/concurrent-ready-1',
            $this->temporaryRoot . '/runtime/concurrent-ready-2',
        ];
        $resultFiles = [
            $this->temporaryRoot . '/runtime/concurrent-result-1.json',
            $this->temporaryRoot . '/runtime/concurrent-result-2.json',
        ];

        foreach (array_merge([$barrier], $readyFiles, $resultFiles) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $pids = [];

        foreach ($requests as $index => $request) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                self::fail('Unable to fork the concurrent submission test process.');
            }

            if ($pid === 0) {
                touch($readyFiles[$index]);
                $deadline = microtime(true) + 5.0;

                while (!is_file($barrier) && microtime(true) < $deadline) {
                    usleep(1000);
                }

                try {
                    $service = new SubmissionService(
                        new SubmissionStorageResolver($this->paths),
                        new PdoFormConfigurationStore(),
                        new PdoSubmissionStore(),
                        new CanonicalSubmissionHasher(),
                        new ProcessAwareIdentifierGenerator(),
                        new FixedClock(),
                    );
                    $accepted = $service->accept($this->temporaryRoot, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', $request);
                    $result = ['status' => 'accepted', 'receipt_id' => $accepted->receiptId];
                } catch (SubmissionFailure $failure) {
                    $result = ['status' => $failure->failureCode, 'error' => $failure->getMessage()];
                } catch (Throwable $throwable) {
                    $result = ['status' => 'error', 'error' => $throwable::class . ': ' . $throwable->getMessage()];
                }

                file_put_contents($resultFiles[$index], json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
                exit(0);
            }

            $pids[] = $pid;
        }

        $deadline = microtime(true) + 5.0;

        while ((!is_file($readyFiles[0]) || !is_file($readyFiles[1])) && microtime(true) < $deadline) {
            usleep(1000);
        }

        self::assertFileExists($readyFiles[0]);
        self::assertFileExists($readyFiles[1]);
        touch($barrier);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $results = [];

        foreach ($resultFiles as $file) {
            self::assertFileExists($file);
            $decoded = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            /** @var array{status: string, receipt_id?: string, error?: string} $decoded */
            $results[] = $decoded;
        }

        return $results;
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

final class SubmissionStorageResolver implements SpokeStorageResolver
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

final class ProcessAwareIdentifierGenerator implements \Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return sprintf('0195f2b8-7c3a-4f42-8c11-%012x', getmypid() % 0xffffffffffff);
    }
}
