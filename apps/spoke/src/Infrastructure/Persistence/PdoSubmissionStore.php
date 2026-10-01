<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Core\Delivery\DeliveryMessageSnapshot;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Submission\Contract\SubmissionStore;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Domain\Submission\SubmissionAccepted;
use Formvex\Spoke\Domain\Submission\SubmissionClassification;
use PDO;
use Throwable;

final class PdoSubmissionStore implements SubmissionStore
{
    public function findAcceptedAttempt(
        PrivateStoragePaths $paths,
        string $publicFormId,
        string $attemptId,
        string $payloadHash,
        DateTimeImmutable $now,
    ): ?SubmissionAccepted {
        $connection = $this->connection($paths);

        try {
            $existing = $this->existingAttempt($connection, $publicFormId, $attemptId);

            return $existing === null ? null : $this->recoverAttempt($existing, $payloadHash, $now);
        } catch (SubmissionFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex could not check the previous submission attempt. Your message was not accepted.');
        }
    }

    public function accept(
        PrivateStoragePaths $paths,
        FormConfigurationRecord $configuration,
        SubmissionRequest $request,
        array $validatedFields,
        string $payloadHash,
        SubmissionClassification $classification,
        DateTimeImmutable $now,
        string $submissionId,
        string $receiptId,
        string $jobId,
        DeliveryMessageSnapshot $deliverySnapshot,
        ?string $qualificationId = null,
    ): SubmissionAccepted {
        $connection = $this->connection($paths);

        try {
            // Acquire the short write lock before reading duplicate evidence. A deferred
            // transaction can deadlock when two writers both read "no attempt" and then
            // upgrade to a write transaction at the same time.
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $active = $this->configurationIdentity($connection, $configuration->publicId, $configuration->versionNumber, $qualificationId !== null);

            if ($active === null) {
                throw new SubmissionFailure('configuration_stale', 'This form configuration is no longer active. Refresh the page and try again.');
            }

            if ($this->integer($active, 'version_number') !== $configuration->versionNumber) {
                throw new SubmissionFailure('configuration_stale', 'This form configuration changed. Refresh the page and try again.');
            }

            $existing = $this->existingAttempt($connection, $configuration->publicId, $request->attemptId);

            if ($existing !== null) {
                $result = $this->recoverAttempt($existing, $payloadHash, $now);
                $connection->commit();

                return $result;
            }

            $timestamp = $this->formatTimestamp($now);
            $expiresAt = $now->modify('+24 hours');
            $fieldsJson = json_encode($validatedFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $parameters = [
                'public_id' => $submissionId,
                'form_id' => $this->integer($active, 'form_id'),
                'configuration_version_id' => $this->integer($active, 'version_id'),
                'configuration_version' => $configuration->versionNumber,
                'page_path' => $configuration->page->path,
                'form_marker' => $configuration->page->formMarker,
                'recipient' => $configuration->recipient,
                'subject' => $configuration->subject,
                'fields_json' => $fieldsJson,
                'classification' => $classification->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            if ($qualificationId !== null && $this->hasColumn($connection, 'submissions', 'qualification_id')) {
                $submission = $connection->prepare(
                    'INSERT INTO submissions '
                    . '(public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, qualification_id, is_qualification_test, created_at, updated_at) '
                    . 'VALUES (:public_id, :form_id, :configuration_version_id, :configuration_version, :page_path, :form_marker, :recipient, :subject, :fields_json, :classification, :qualification_id, 1, :created_at, :updated_at)',
                );
                $parameters['qualification_id'] = $qualificationId;
            } else {
                $submission = $connection->prepare(
                    'INSERT INTO submissions '
                    . '(public_id, form_id, configuration_version_id, configuration_version, page_path, form_marker, recipient, subject, fields_json, classification, created_at, updated_at) '
                    . 'VALUES (:public_id, :form_id, :configuration_version_id, :configuration_version, :page_path, :form_marker, :recipient, :subject, :fields_json, :classification, :created_at, :updated_at)',
                );
            }
            $submission->execute($parameters);
            $submissionRowId = $this->lastInsertId($connection);

            $attempt = $connection->prepare(
                'INSERT INTO submission_attempts '
                . '(public_form_id, attempt_id, submission_id, payload_hash, receipt_id, accepted_at, expires_at) '
                . 'VALUES (:public_form_id, :attempt_id, :submission_id, :payload_hash, :receipt_id, :accepted_at, :expires_at)',
            );
            $attempt->execute([
                'public_form_id' => $configuration->publicId,
                'attempt_id' => $request->attemptId,
                'submission_id' => $submissionRowId,
                'payload_hash' => $payloadHash,
                'receipt_id' => $receiptId,
                'accepted_at' => $timestamp,
                'expires_at' => $this->formatTimestamp($expiresAt),
            ]);

            $jobParameters = [
                'job_id' => $jobId,
                'submission_id' => $submissionRowId,
                'due_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $snapshotJson = json_encode($deliverySnapshot->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if ($this->hasColumn($connection, 'delivery_jobs', 'snapshot_json')) {
                $job = $connection->prepare(
                    'INSERT INTO delivery_jobs '
                    . '(job_id, submission_id, state, attempt_count, due_at, snapshot_json, created_at, updated_at) '
                    . "VALUES (:job_id, :submission_id, 'queued', 0, :due_at, :snapshot_json, :created_at, :updated_at)",
                );
                $jobParameters['snapshot_json'] = $snapshotJson;
            } else {
                $job = $connection->prepare(
                    'INSERT INTO delivery_jobs '
                    . '(job_id, submission_id, state, attempt_count, due_at, created_at, updated_at) '
                    . "VALUES (:job_id, :submission_id, 'queued', 0, :due_at, :created_at, :updated_at)",
                );
            }
            $job->execute($jobParameters);
            if ($this->hasTable($connection, 'delivery_attempt_cycles')) {
                $jobRow = $connection->prepare('SELECT id FROM delivery_jobs WHERE job_id = :job_id LIMIT 1');
                $jobRow->execute(['job_id' => $jobId]);
                $deliveryJobId = $jobRow->fetchColumn();
                if (!is_numeric($deliveryJobId)) {
                    throw new SubmissionFailure('storage_unavailable', 'Formvex could not create the delivery record. Your message was not accepted.');
                }
                $cycle = $connection->prepare(
                    "INSERT INTO delivery_attempt_cycles (delivery_job_id, cycle_number, origin, state, attempt_count, created_at, updated_at) VALUES (:job_id, 1, 'automatic', 'queued', 0, :created_at, :updated_at)",
                );
                $cycle->execute(['job_id' => (int) $deliveryJobId, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
                $cycleId = $this->lastInsertId($connection);
                $activeCycle = $connection->prepare('UPDATE delivery_jobs SET active_cycle_id = :cycle_id WHERE id = :job_id');
                $activeCycle->execute(['cycle_id' => $cycleId, 'job_id' => (int) $deliveryJobId]);
            }
            $connection->commit();

            return new SubmissionAccepted($receiptId, (string) $submissionRowId);
        } catch (SubmissionFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);

            try {
                $existing = $this->existingAttempt($connection, $configuration->publicId, $request->attemptId);

                if ($existing !== null) {
                    return $this->recoverAttempt($existing, $payloadHash, $now);
                }
            } catch (SubmissionFailure $failure) {
                throw $failure;
            } catch (Throwable) {
                // Return the safe storage failure below. No database detail is public.
            }

            throw new SubmissionFailure('storage_unavailable', 'Formvex could not safely store your message. Your message was not accepted. Please try again later.');
        }
    }

    /** @return array<string, mixed>|null */
    private function configurationIdentity(PDO $connection, string $publicId, int $versionNumber, bool $qualification): ?array
    {
        $state = $qualification ? 'published' : 'active';
        $statement = $connection->prepare(
            "SELECT f.id AS form_id, v.id AS version_id, v.version_number
             FROM form_configurations f
             INNER JOIN form_configuration_versions v ON v.form_id = f.id AND v.state = :state
             WHERE f.public_id = :public_id AND f.deleted_at IS NULL AND v.version_number = :version_number
             LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId, 'state' => $state, 'version_number' => $versionNumber]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function existingAttempt(PDO $connection, string $publicFormId, string $attemptId): ?array
    {
        $statement = $connection->prepare(
            'SELECT payload_hash, receipt_id, expires_at FROM submission_attempts WHERE public_form_id = :public_form_id AND attempt_id = :attempt_id LIMIT 1',
        );
        $statement->execute(['public_form_id' => $publicFormId, 'attempt_id' => $attemptId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $normalized = [];

        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $attempt */
    private function recoverAttempt(array $attempt, string $payloadHash, DateTimeImmutable $now): SubmissionAccepted
    {
        if (!is_string($attempt['payload_hash'] ?? null) || !is_string($attempt['receipt_id'] ?? null) || !is_string($attempt['expires_at'] ?? null)) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex found invalid duplicate-protection state. Your message was not accepted.');
        }

        try {
            $expiresAt = new DateTimeImmutable($attempt['expires_at'], new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex found invalid duplicate-protection state. Your message was not accepted.');
        }

        if ($expiresAt <= $now) {
            throw new SubmissionFailure('attempt_expired', 'This submission attempt has expired. Start a new submission and try again.');
        }

        if (!hash_equals($attempt['payload_hash'], $payloadHash)) {
            throw new SubmissionFailure('attempt_conflict', 'This submission attempt contains different form data. Start a new submission and try again.');
        }

        return new SubmissionAccepted($attempt['receipt_id']);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex could not access its local database. Your message was not accepted.');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');

            return $connection;
        } catch (Throwable) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex could not access its local database. Your message was not accepted.');
        }
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !is_string($row[$key] ?? null) && !is_float($row[$key] ?? null)) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex found invalid active form state. Your message was not accepted.');
        }

        return (int) $row[$key];
    }

    private function lastInsertId(PDO $connection): int
    {
        $value = $connection->lastInsertId();

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new SubmissionFailure('storage_unavailable', 'Formvex could not record the accepted message. Your message was not accepted.');
        }

        return (int) $value;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function hasColumn(PDO $connection, string $table, string $column): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
    }

    private function hasTable(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
        $statement->execute(['table_name' => $table]);

        return $statement->fetchColumn() !== false;
    }
}
