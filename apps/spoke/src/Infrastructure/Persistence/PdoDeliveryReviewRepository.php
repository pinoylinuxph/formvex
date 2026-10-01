<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryReviewRepository;
use Formvex\Spoke\Domain\Delivery\DeliveryResendResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewAttempt;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewDetails;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewFailure;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewListItem;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewListResult;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewQuery;
use Formvex\Spoke\Domain\Delivery\DeliveryWarning;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use PDO;
use Throwable;

final class PdoDeliveryReviewRepository implements DeliveryReviewRepository
{
    public function list(PrivateStoragePaths $paths, DeliveryReviewQuery $query): DeliveryReviewListResult
    {
        $connection = $this->connection($paths);
        [$where, $parameters] = $this->where($query);

        try {
            $count = $connection->prepare(
                'SELECT COUNT(*) FROM delivery_jobs d INNER JOIN submissions s ON s.id = d.submission_id '
                . 'INNER JOIN form_configurations f ON f.id = s.form_id ' . $where,
            );
            $count->execute($parameters);
            $total = (int) $count->fetchColumn();
            $offset = ($query->page - 1) * $query->pageSize;
            $order = $query->sort === 'oldest' ? 'ASC' : 'DESC';
            $statement = $connection->prepare(
                'SELECT d.job_id, d.state, d.attempt_count, d.due_at, d.last_error_code, d.last_outcome, d.updated_at, '
                . 's.public_id AS submission_public_id, s.recipient, s.configuration_version, f.display_name '
                . 'FROM delivery_jobs d INNER JOIN submissions s ON s.id = d.submission_id '
                . 'INNER JOIN form_configurations f ON f.id = s.form_id ' . $where
                . " ORDER BY d.updated_at {$order}, d.id {$order} LIMIT :limit OFFSET :offset",
            );
            foreach ($parameters as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->bindValue(':limit', $query->pageSize, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();
            $items = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $item = $this->associativeRow($row);
                if ($item !== null) {
                    $items[] = $this->listItem($item);
                }
            }

            return new DeliveryReviewListResult($items, $total, $query->page, $query->pageSize, max(1, (int) ceil($total / $query->pageSize)));
        } catch (DeliveryReviewFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new DeliveryReviewFailure('storage_unavailable', 'The delivery list could not be loaded. Check the local database and try again.', $failure);
        }
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?DeliveryReviewDetails
    {
        $connection = $this->connection($paths);

        try {
            $statement = $connection->prepare(
                'SELECT d.id, d.job_id, d.state, d.attempt_count, d.due_at, d.last_error_code, d.last_outcome, d.updated_at, '
                . 's.public_id AS submission_public_id, s.recipient, s.configuration_version, s.created_at, f.display_name '
                . 'FROM delivery_jobs d INNER JOIN submissions s ON s.id = d.submission_id '
                . 'INNER JOIN form_configurations f ON f.id = s.form_id WHERE d.job_id = :job_id LIMIT 1',
            );
            $statement->execute(['job_id' => $publicId]);
            $row = $this->associativeRow($statement->fetch(PDO::FETCH_ASSOC));
            if ($row === null) {
                return null;
            }

            return $this->details($connection, $row);
        } catch (DeliveryReviewFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new DeliveryReviewFailure('storage_unavailable', 'The delivery details could not be loaded. Check the local database and try again.', $failure);
        }
    }

    public function resend(PrivateStoragePaths $paths, string $publicId, bool $confirmUncertain, DateTimeImmutable $now): DeliveryResendResult
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($now);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $statement = $connection->prepare(
                'SELECT d.id, d.state, d.active_cycle_id, s.public_id AS submission_public_id '
                . 'FROM delivery_jobs d INNER JOIN submissions s ON s.id = d.submission_id WHERE d.job_id = :job_id LIMIT 1',
            );
            $statement->execute(['job_id' => $publicId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->reject($connection, $publicId, $timestamp, 'not_found', 'The requested delivery record could not be found.');
            }

            $state = is_string($row['state'] ?? null) ? $row['state'] : '';
            $jobId = $this->integer($row['id'] ?? null);
            $manualCountStatement = $connection->prepare("SELECT COUNT(*) FROM delivery_attempt_cycles WHERE delivery_job_id = :job_id AND origin = 'manual'");
            $manualCountStatement->execute(['job_id' => $jobId]);
            $manualCount = (int) $manualCountStatement->fetchColumn();
            if (!in_array($state, ['failed', 'uncertain'], true)) {
                $this->reject($connection, $publicId, $timestamp, 'not_eligible', 'This delivery is not eligible for resend. Only failed or uncertain deliveries can be resent.');
            }
            if ($manualCount >= 3) {
                $this->reject($connection, $publicId, $timestamp, 'resend_limit_reached', 'Manual resend limit reached. This delivery remains available for review, but no further resend can be queued.');
            }
            if ($state === 'uncertain' && !$confirmUncertain) {
                $this->reject($connection, $publicId, $timestamp, 'uncertain_confirmation_required', 'Confirm that you understand this resend may deliver the same email twice before continuing.');
            }

            $cycleNumberStatement = $connection->prepare('SELECT COALESCE(MAX(cycle_number), 0) + 1 FROM delivery_attempt_cycles WHERE delivery_job_id = :job_id');
            $cycleNumberStatement->execute(['job_id' => $jobId]);
            $cycleNumber = (int) $cycleNumberStatement->fetchColumn();
            $cycle = $connection->prepare(
                "INSERT INTO delivery_attempt_cycles (delivery_job_id, cycle_number, origin, state, attempt_count, last_error_code, created_at, updated_at) VALUES (:job_id, :cycle_number, 'manual', 'queued', 0, NULL, :created_at, :updated_at)",
            );
            $cycle->execute(['job_id' => $jobId, 'cycle_number' => $cycleNumber, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
            $cycleId = $this->integer($connection->lastInsertId());
            $update = $connection->prepare(
                "UPDATE delivery_jobs SET state = 'queued', attempt_count = 0, due_at = :due_at, last_error_code = NULL, last_outcome = NULL, active_cycle_id = :cycle_id, lease_token = NULL, lease_expires_at = NULL, updated_at = :updated_at WHERE id = :id AND state IN ('failed', 'uncertain')",
            );
            $update->execute(['due_at' => $timestamp, 'cycle_id' => $cycleId, 'updated_at' => $timestamp, 'id' => $jobId]);
            if ($update->rowCount() !== 1) {
                throw new DeliveryReviewFailure('stale_state', 'This delivery changed before the resend was queued. Reload the page and review its current state.');
            }
            $event = $state === 'uncertain' ? 'spoke.delivery_review.uncertain_resend_confirmed' : 'spoke.delivery_review.resend_queued';
            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (:event, \'success\', :occurred_at, \'delivery\', :public_id)',
            );
            $audit->execute(['event' => $event, 'occurred_at' => $timestamp, 'public_id' => $publicId]);
            $connection->commit();

            return new DeliveryResendResult(true, 'A new delivery attempt cycle was queued. The delivery worker will process it on its next run.', $cycleNumber);
        } catch (DeliveryReviewFailure $failure) {
            $this->rollback($connection);
            $this->recordRejectedAudit($connection, $publicId, $timestamp, $failure->failureCode);
            throw $failure;
        } catch (Throwable $failure) {
            $this->rollback($connection);
            throw new DeliveryReviewFailure('storage_unavailable', 'The resend could not be queued. No delivery state was changed.', $failure);
        }
    }

    public function warnings(PrivateStoragePaths $paths, DateTimeImmutable $now): array
    {
        $connection = $this->connection($paths);
        $warnings = [];
        $timestamp = $this->formatTimestamp($now);
        $smtpWarning = $this->smtpWarning($paths);
        if ($smtpWarning !== null) {
            $warnings[] = $smtpWarning;
        }
        $attention = $connection->query("SELECT COUNT(*) FROM delivery_jobs WHERE state IN ('failed', 'uncertain')");
        $attentionCount = $attention === false ? 0 : (int) $attention->fetchColumn();
        if ($attentionCount > 0) {
            $warnings[] = new DeliveryWarning('warning', 'Delivery attention required', sprintf('%d failed or uncertain delivery record(s) require administrator review.', $attentionCount));
        }

        $heartbeat = $connection->query('SELECT last_success_at FROM delivery_worker_heartbeat WHERE singleton_id = 1');
        $lastSuccess = $heartbeat === false ? false : $heartbeat->fetchColumn();
        if (!is_string($lastSuccess) || $lastSuccess === '') {
            $warnings[] = new DeliveryWarning('warning', 'Scheduler status is not confirmed', 'The delivery worker has not reported a successful run yet. Run the delivery command or inspect the scheduler configuration.');
        } else {
            $age = $this->ageSeconds($lastSuccess, $now);
            if ($age >= 900) {
                $warnings[] = new DeliveryWarning('critical', 'Scheduler warning', 'The delivery worker has not reported a successful run within 15 minutes. Inspect the scheduler configuration before resending messages.');
            } elseif ($age >= 300) {
                $warnings[] = new DeliveryWarning('warning', 'Scheduler warning', 'The delivery worker has not reported a successful run within 5 minutes. Inspect the scheduler configuration.');
            }
        }

        $due = $connection->prepare("SELECT due_at FROM delivery_jobs WHERE state = 'queued' AND due_at <= :now ORDER BY due_at ASC LIMIT 1");
        $due->execute(['now' => $timestamp]);
        $oldestDue = $due->fetchColumn();
        if (is_string($oldestDue) && $oldestDue !== '') {
            $age = $this->ageSeconds($oldestDue, $now);
            if ($age >= 900) {
                $warnings[] = new DeliveryWarning('critical', 'Delivery queue is delayed', 'The oldest due delivery has been waiting for at least 15 minutes. Run the delivery worker and inspect the scheduler.');
            } elseif ($age >= 300) {
                $warnings[] = new DeliveryWarning('warning', 'Delivery queue is delayed', 'The oldest due delivery has been waiting for at least 5 minutes. Check the delivery worker.');
            }
        }

        return $warnings;
    }

    /** @param array<string, mixed> $row */
    private function listItem(array $row): DeliveryReviewListItem
    {
        return new DeliveryReviewListItem(
            $this->string($row, 'job_id'),
            $this->string($row, 'submission_public_id'),
            $this->string($row, 'display_name'),
            $this->string($row, 'state'),
            $this->outcomeLabel($row['last_outcome'] ?? null),
            $this->integer($row['attempt_count'] ?? null) . ' / 6',
            $this->string($row, 'updated_at'),
            $this->nullableString($row['due_at'] ?? null),
            $this->maskRecipient($this->string($row, 'recipient')),
            $this->integer($row['configuration_version'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function details(PDO $connection, array $row): DeliveryReviewDetails
    {
        $jobId = $this->integer($row['id'] ?? null);
        $attemptStatement = $connection->prepare(
            'SELECT c.cycle_number, c.origin, a.attempt_number, a.outcome, a.error_code, a.started_at, a.completed_at, a.next_due_at '
            . 'FROM delivery_attempts a INNER JOIN delivery_attempt_cycles c ON c.id = a.cycle_id '
            . 'WHERE a.job_id = :job_id ORDER BY c.cycle_number DESC, a.attempt_number DESC',
        );
        $attemptStatement->execute(['job_id' => $jobId]);
        $attempts = [];
        foreach ($attemptStatement->fetchAll(PDO::FETCH_ASSOC) as $attempt) {
            $attempt = $this->associativeRow($attempt);
            if ($attempt === null) {
                continue;
            }
            [$errorCode, $errorMessage] = $this->safeError($attempt['error_code'] ?? null);
            $attempts[] = new DeliveryReviewAttempt(
                $this->integer($attempt['cycle_number'] ?? null),
                $this->string($attempt, 'origin'),
                $this->integer($attempt['attempt_number'] ?? null),
                $this->outcomeLabel($attempt['outcome'] ?? null),
                $errorCode,
                $errorMessage,
                $this->string($attempt, 'started_at'),
                $this->nullableString($attempt['completed_at'] ?? null),
                $this->nullableString($attempt['next_due_at'] ?? null),
            );
        }
        $manual = $connection->prepare("SELECT COUNT(*) FROM delivery_attempt_cycles WHERE delivery_job_id = :job_id AND origin = 'manual'");
        $manual->execute(['job_id' => $jobId]);
        $manualCount = (int) $manual->fetchColumn();
        $audit = $connection->prepare(
            "SELECT event_name, outcome, occurred_at FROM audit_events WHERE resource_type = 'delivery' AND resource_public_id = :public_id ORDER BY occurred_at DESC, id DESC LIMIT 50",
        );
        $audit->execute(['public_id' => $this->string($row, 'job_id')]);
        $auditEvents = [];
        foreach ($audit->fetchAll(PDO::FETCH_ASSOC) as $event) {
            if (is_array($event) && is_string($event['event_name'] ?? null) && is_string($event['outcome'] ?? null) && is_string($event['occurred_at'] ?? null)) {
                $auditEvents[] = [
                    'event' => $this->auditLabel($event['event_name']),
                    'outcome' => $this->auditOutcome($event['outcome']),
                    'occurredAt' => $event['occurred_at'],
                ];
            }
        }
        $state = $this->string($row, 'state');
        $allowed = in_array($state, ['failed', 'uncertain'], true) && $manualCount < 3;
        $reason = $allowed ? '' : $this->resendUnavailableReason($state, $manualCount);
        [$errorCode, $errorMessage] = $this->safeError($row['last_error_code'] ?? null);

        return new DeliveryReviewDetails(
            $this->string($row, 'job_id'),
            $this->string($row, 'submission_public_id'),
            $this->string($row, 'display_name'),
            $this->integer($row['configuration_version'] ?? null),
            $state,
            $this->outcomeLabel($row['last_outcome'] ?? null),
            $errorCode,
            $errorMessage,
            $this->maskRecipient($this->string($row, 'recipient')),
            $this->string($row, 'created_at'),
            $this->string($row, 'updated_at'),
            $this->nullableString($row['due_at'] ?? null),
            $this->integer($row['attempt_count'] ?? null),
            $manualCount,
            $allowed,
            $state === 'uncertain',
            $reason,
            $attempts,
            $auditEvents,
        );
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function where(DeliveryReviewQuery $query): array
    {
        $clauses = ['1 = 1'];
        $parameters = [];
        if ($query->state !== 'all') {
            $clauses[] = 'd.state = :state';
            $parameters['state'] = $query->state;
        }
        if ($query->outcome !== 'all') {
            $clauses[] = 'd.last_outcome = :outcome';
            $parameters['outcome'] = $query->outcome;
        }
        if ($query->formPublicId !== null) {
            $clauses[] = 'f.public_id = :form_public_id';
            $parameters['form_public_id'] = $query->formPublicId;
        }

        return [' WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    private function reject(PDO $connection, string $publicId, string $timestamp, string $code, string $message): never
    {
        $audit = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (\'spoke.delivery_review.resend_rejected\', :outcome, :occurred_at, \'delivery\', :public_id)',
        );
        $audit->execute(['outcome' => $code, 'occurred_at' => $timestamp, 'public_id' => $publicId]);
        throw new DeliveryReviewFailure($code, $message);
    }

    private function recordRejectedAudit(PDO $connection, string $publicId, string $timestamp, string $code): void
    {
        if (!in_array($code, ['not_found', 'not_eligible', 'resend_limit_reached', 'uncertain_confirmation_required', 'stale_state'], true)) {
            return;
        }

        try {
            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (\'spoke.delivery_review.resend_rejected\', :outcome, :occurred_at, \'delivery\', :public_id)',
            );
            $audit->execute(['outcome' => $code, 'occurred_at' => $timestamp, 'public_id' => $publicId]);
        } catch (Throwable) {
            // A rejected action must not become a successful action if its audit row cannot be written.
        }
    }

    /** @return array{0: string, 1: string} */
    private function safeError(mixed $value): array
    {
        $code = is_string($value) ? $value : '';
        return match ($code) {
            'smtp_authentication_failed' => [$code, 'The SMTP server rejected the configured credentials. Recheck Settings, run the SMTP test, then retry.'],
            'smtp_tls_failed' => [$code, 'The SMTP TLS connection could not be established. Verify the encryption mode, hostname, port, and certificate requirements.'],
            'smtp_temporary_failure' => [$code, 'The SMTP server returned a temporary failure. Review the attempt history before resending.'],
            'smtp_recipient_rejected' => [$code, 'The SMTP server rejected the configured recipient. Review the saved form recipient before accepting new messages.'],
            'delivery_uncertain', 'smtp_result_uncertain', 'stale_lease' => ['delivery_uncertain', 'Transmission may have occurred, but acceptance was not confirmed. Resending may deliver the same email twice.'],
            'retry_exhausted' => [$code, 'All automatic delivery attempts were exhausted. Review the SMTP configuration and resend explicitly if permitted.'],
            'delivery_snapshot_invalid', 'delivery_composition_failed' => ['delivery_snapshot_invalid', 'The saved delivery message could not be composed safely. Preserve this record and investigate the local configuration.'],
            '' => ['', 'No delivery failure has been recorded.'],
            default => ['delivery_failure', 'The delivery worker recorded a failure that requires administrator review.'],
        };
    }

    private function outcomeLabel(mixed $outcome): string
    {
        return match (is_string($outcome) ? $outcome : '') {
            'accepted' => 'Accepted',
            'temporary_failure' => 'Temporary failure',
            'permanent_failure' => 'Permanent/configuration failure',
            'uncertain' => 'Uncertain',
            'started' => 'Processing',
            default => 'Not attempted',
        };
    }

    private function resendUnavailableReason(string $state, int $manualCount): string
    {
        if ($manualCount >= 3) {
            return 'Manual resend limit reached. This delivery remains available for review, but no further resend can be queued.';
        }
        return match ($state) {
            'queued' => 'This delivery is already queued for the worker.',
            'processing' => 'A worker is currently processing this delivery.',
            'sent' => 'The SMTP server accepted this message. A resend is not available from this state.',
            default => 'Only failed or uncertain deliveries can be resent.',
        };
    }

    private function auditLabel(string $event): string
    {
        return match ($event) {
            'spoke.delivery_review.resend_queued' => 'Manual resend queued',
            'spoke.delivery_review.uncertain_resend_confirmed' => 'Uncertain resend confirmed',
            'spoke.delivery_review.resend_rejected' => 'Resend rejected',
            default => 'Delivery review event',
        };
    }

    private function auditOutcome(string $outcome): string
    {
        return match ($outcome) {
            'success' => 'Success',
            'not_found' => 'Record not found',
            'not_eligible' => 'Not eligible',
            'resend_limit_reached' => 'Limit reached',
            'uncertain_confirmation_required' => 'Confirmation required',
            'stale_state' => 'State changed',
            default => 'Rejected',
        };
    }

    private function smtpWarning(PrivateStoragePaths $paths): ?DeliveryWarning
    {
        try {
            $settingsStore = new PdoInstallationSettingsStore();
            $settings = $settingsStore->get($paths);
            $test = $settingsStore->getTestState($paths);
            if ($settings->smtpHost === '' || $settings->senderEmail === '') {
                return new DeliveryWarning('warning', 'SMTP delivery is not ready', 'Configure the SMTP host and sender email in Settings, then send an SMTP test before accepting delivery work.');
            }
            if ($test->status !== SmtpTestStatus::PASSED || $test->testedRevision !== $settings->smtpConfigurationRevision) {
                $message = match ($test->status) {
                    SmtpTestStatus::NOT_CONFIGURED => 'Send an SMTP test in Settings before accepting delivery work.',
                    SmtpTestStatus::FAILED => 'The latest SMTP test failed. Correct the settings, send a new test, and review the failure details before resending.',
                    SmtpTestStatus::UNCERTAIN => 'The latest SMTP test has an uncertain result. Review Settings and run a new test before resending.',
                    SmtpTestStatus::STALE => 'SMTP settings changed after the last successful test. Send a new SMTP test before resending.',
                    SmtpTestStatus::PASSED => 'The SMTP settings changed after the last successful test. Send a new SMTP test before resending.',
                };

                return new DeliveryWarning('warning', 'SMTP test requires attention', $message);
            }
        } catch (Throwable) {
            return new DeliveryWarning('critical', 'SMTP readiness could not be confirmed', 'Open Settings and verify the SMTP configuration and test result before resending delivery records.');
        }

        return null;
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');
            if (!$this->hasTable($connection, 'delivery_attempt_cycles')) {
                throw new DeliveryReviewFailure('storage_unavailable', 'Delivery review is unavailable until the local database migration is applied.');
            }

            return $connection;
        } catch (DeliveryReviewFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new DeliveryReviewFailure('storage_unavailable', 'The delivery database could not be opened. Check the local database and try again.', $failure);
        }
    }

    private function hasTable(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
        $statement->execute(['table_name' => $table]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    private function associativeRow(mixed $row): ?array
    {
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

    private function ageSeconds(string $timestamp, DateTimeImmutable $now): int
    {
        try {
            $date = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));

            return max(0, $now->getTimestamp() - $date->getTimestamp());
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function integer(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }
        throw new DeliveryReviewFailure('storage_unavailable', 'The delivery database returned invalid internal state.');
    }

    private function maskRecipient(string $recipient): string
    {
        $at = strrpos($recipient, '@');
        if ($at === false || $at < 1 || $at === strlen($recipient) - 1) {
            return 'Configured recipient';
        }
        $local = substr($recipient, 0, $at);
        $domain = substr($recipient, $at + 1);
        $parts = explode('.', $domain);
        $maskedDomain = substr($domain, 0, 1) . str_repeat('•', max(1, strlen($domain) - 1));
        if (count($parts) > 1) {
            $maskedDomain = substr($parts[0], 0, 1) . str_repeat('•', max(1, strlen($parts[0]) - 1)) . '.' . end($parts);
        }

        return substr($local, 0, 1) . str_repeat('•', max(1, strlen($local) - 1)) . '@' . $maskedDomain;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
}
