<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoSubmissionReviewRepository;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class SubmissionReviewRepositoryTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-review-' . bin2hex(random_bytes(8));
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
        new PdoInstallationStore($this->runner(), new FixedClock(), new FixedIdentifierGenerator())->initialize($this->paths);
        $this->seedSubmission();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testDefaultListHidesQualificationTestsAndDetailMasksRecipient(): void
    {
        $repository = new PdoSubmissionReviewRepository();
        $result = $repository->list($this->paths, SubmissionReviewQuery::fromInput([]));

        self::assertSame(1, $result->total);
        self::assertSame('submission-0001', $result->items[0]->publicId);
        self::assertSame('o••••@e••••••.com', $result->items[0]->recipientMasked);

        $details = $repository->find($this->paths, 'submission-0001');
        self::assertNotNull($details);
        self::assertSame('<script>alert(1)</script>', $details->fields[0]->value);
        self::assertStringNotContainsString('owner@example.com', $details->recipientMasked);
    }

    public function testLifecycleActionsAreAtomicAndRestorePreviousState(): void
    {
        $repository = new PdoSubmissionReviewRepository();
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');

        self::assertTrue($repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::HANDLED, $now)->changed);
        self::assertTrue($repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::TRASH, $now)->changed);
        self::assertTrue($repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::RESTORE, $now)->changed);
        self::assertSame('handled', $repository->find($this->paths, 'submission-0001')?->lifecycle);

        $connection = $this->connection();
        $eventCount = (int) $connection->query("SELECT COUNT(*) FROM audit_events WHERE resource_type = 'submission' AND resource_public_id = 'submission-0001' AND outcome = 'success'")->fetchColumn();
        self::assertSame(3, $eventCount);
    }

    public function testPermanentDeleteRequiresTrashAndRemovesOwnedRecordsOnly(): void
    {
        $repository = new PdoSubmissionReviewRepository();
        $now = new DateTimeImmutable('2026-10-01T00:00:00Z');

        $notTrash = $repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::DELETE, $now);
        self::assertFalse($notTrash->changed);
        self::assertStringContainsString('Trash', $notTrash->message);

        $repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::TRASH, $now);
        $deleted = $repository->apply($this->paths, 'submission-0001', SubmissionReviewAction::DELETE, $now);
        self::assertTrue($deleted->changed);
        self::assertNull($repository->find($this->paths, 'submission-0001'));

        $connection = $this->connection();
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM submission_attempts')->fetchColumn());
        self::assertSame(0, (int) $connection->query('SELECT COUNT(*) FROM delivery_jobs')->fetchColumn());
        self::assertSame(1, (int) $connection->query("SELECT COUNT(*) FROM audit_events WHERE resource_public_id = 'submission-0001' AND event_name = 'spoke.submission.delete' AND outcome = 'success'")->fetchColumn());
    }

    public function testQueryBoundsInvalidValuesAndQualificationFilter(): void
    {
        $query = SubmissionReviewQuery::fromInput(['page_size' => '101', 'sort' => 'unsafe']);
        self::assertSame(25, $query->pageSize);
        self::assertNotEmpty($query->errors);

        $connection = $this->connection();
        $connection->exec("UPDATE submissions SET is_qualification_test = 1 WHERE public_id = 'submission-0001'");
        $repository = new PdoSubmissionReviewRepository();
        self::assertSame(0, $repository->list($this->paths, SubmissionReviewQuery::fromInput([]))->total);
        self::assertSame(1, $repository->list($this->paths, SubmissionReviewQuery::fromInput(['record_type' => 'qualification']))->total);
    }

    private function runner(): SqliteMigrationRunner
    {
        return new SqliteMigrationRunner(
            new \Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata(),
            new \Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth(),
            new \Formvex\Spoke\Migrations\Version000003CreateInstallationSettings(),
            new \Formvex\Spoke\Migrations\Version000004CreateFormConfiguration(),
            new \Formvex\Spoke\Migrations\Version000005CreateFormDiscovery(),
            new FixedClock(),
            new \Formvex\Spoke\Migrations\Version000006CreateSubmissions(),
            new \Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse(),
            new \Formvex\Spoke\Migrations\Version000008CreateEmailDeliveryWorker(),
            new \Formvex\Spoke\Migrations\Version000009CreateFormActivation(),
            new \Formvex\Spoke\Migrations\Version000010CreateInstallationBranding(),
            new \Formvex\Spoke\Migrations\Version000011CreateSubmissionReview(),
        );
    }

    private function seedSubmission(): void
    {
        $connection = $this->connection();
        $timestamp = '2026-09-30T12:00:00.000000Z';
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, 'form-0001', 'Contact form', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, recipient, subject, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, 'owner@example.com', 'Contact message', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO form_configuration_version_pages (version_id, host, path, form_marker) VALUES (1, 'example.com', '/', 'contactForm')");
        $connection->exec("INSERT INTO form_configuration_version_fields (id, version_id, field_key, control_name, control_type, display_label, parameter_key, ordinal, is_required, max_length) VALUES (1, 1, 'message', 'message', 'textarea', 'Message', 'message', 0, 0, 10000)");
        $connection->exec("INSERT INTO submissions (id, public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, created_at, updated_at) VALUES (1, 'submission-0001', 1, 1, 1, '/', 'contactForm', 'owner@example.com', 'Contact message', '{\"message\":\"<script>alert(1)</script>\"}', 'normal', '$timestamp', '$timestamp')");
        $connection->exec("INSERT INTO submission_attempts (public_form_id, attempt_id, submission_id, payload_hash, receipt_id, accepted_at, expires_at) VALUES ('form-0001', 'attempt-0001', 1, 'hash', 'receipt-0001', '$timestamp', '2026-10-01T12:00:00.000000Z')");
        $connection->exec("INSERT INTO delivery_jobs (id, job_id, submission_id, state, attempt_count, due_at, created_at, updated_at) VALUES (1, 'job-0001', 1, 'sent', 1, '$timestamp', '$timestamp', '$timestamp')");
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
