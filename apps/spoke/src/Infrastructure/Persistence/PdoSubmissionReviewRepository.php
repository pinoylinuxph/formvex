<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\SubmissionReview\Contract\SubmissionReviewRepository;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewActionResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewDetails;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewField;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewListItem;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewListResult;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use PDO;
use Throwable;

final class PdoSubmissionReviewRepository implements SubmissionReviewRepository
{
    public function list(PrivateStoragePaths $paths, SubmissionReviewQuery $query): SubmissionReviewListResult
    {
        $connection = $this->connection($paths);
        [$where, $parameters] = $this->where($query);

        try {
            $count = $connection->prepare(
                'SELECT COUNT(*) FROM submissions s INNER JOIN form_configurations f ON f.id = s.form_id '
                . 'LEFT JOIN delivery_jobs d ON d.submission_id = s.id ' . $where,
            );
            $count->execute($parameters);
            $total = (int) $count->fetchColumn();
            $offset = ($query->page - 1) * $query->pageSize;
            $order = $query->sort === 'oldest' ? 'ASC' : 'DESC';
            $statement = $connection->prepare(
                'SELECT s.public_id, f.public_id AS form_public_id, f.display_name, s.created_at, '
                . 's.classification, s.state, s.is_qualification_test, s.recipient, d.state AS delivery_state '
                . 'FROM submissions s INNER JOIN form_configurations f ON f.id = s.form_id '
                . 'LEFT JOIN delivery_jobs d ON d.submission_id = s.id ' . $where
                . ' ORDER BY s.created_at ' . $order . ', s.id ' . $order . ' LIMIT :limit OFFSET :offset',
            );
            foreach ($parameters as $key => $value) {
                $statement->bindValue($key, $value);
            }
            $statement->bindValue(':limit', $query->pageSize, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
            $statement->execute();
            $items = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
                if (!is_array($rawRow)) {
                    continue;
                }
                $items[] = $this->listItem($this->normalizeRow($rawRow));
            }

            return new SubmissionReviewListResult(
                $items,
                $total,
                $query->page,
                $query->pageSize,
                max(1, (int) ceil($total / $query->pageSize)),
            );
        } catch (SubmissionReviewFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new SubmissionReviewFailure('storage_unavailable', 'The submission list could not be loaded. Check the local database and try again.', $failure);
        }
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?SubmissionReviewDetails
    {
        $connection = $this->connection($paths);

        try {
            $statement = $connection->prepare(
                'SELECT s.*, f.public_id AS form_public_id, f.display_name, d.state AS delivery_state, a.receipt_id '
                . 'FROM submissions s INNER JOIN form_configurations f ON f.id = s.form_id '
                . 'LEFT JOIN delivery_jobs d ON d.submission_id = s.id LEFT JOIN submission_attempts a ON a.submission_id = s.id '
                . 'WHERE s.public_id = :public_id LIMIT 1',
            );
            $statement->execute(['public_id' => $publicId]);
            $rawRow = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($rawRow)) {
                return null;
            }
            $row = $this->normalizeRow($rawRow);

            return $this->details($connection, $row);
        } catch (SubmissionReviewFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new SubmissionReviewFailure('storage_unavailable', 'The submission details could not be loaded. Check the local database and try again.', $failure);
        }
    }

    public function apply(PrivateStoragePaths $paths, string $publicId, SubmissionReviewAction $action, DateTimeImmutable $now): SubmissionReviewActionResult
    {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($now);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $row = $this->actionRow($connection, $publicId);

            if ($row === null) {
                $this->rollback($connection);
                throw new SubmissionReviewFailure('not_found', 'The requested submission could not be found. It may have been removed or is no longer available.');
            }

            $result = $this->transition($connection, $row, $publicId, $action, $timestamp);
            $connection->commit();

            return $result;
        } catch (SubmissionReviewFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable $failure) {
            $this->rollback($connection);
            throw new SubmissionReviewFailure('storage_unavailable', 'The review action could not be saved. No submission state was changed.', $failure);
        }
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function where(SubmissionReviewQuery $query): array
    {
        $clauses = ['1 = 1'];
        $parameters = [];

        if ($query->formPublicId !== null) {
            $clauses[] = 'f.public_id = :form_public_id';
            $parameters['form_public_id'] = $query->formPublicId;
        }
        if ($query->classification !== 'all') {
            $clauses[] = 's.classification = :classification';
            $parameters['classification'] = $query->classification;
        }
        if ($query->lifecycle !== 'all') {
            $clauses[] = 's.state = :lifecycle';
            $parameters['lifecycle'] = $query->lifecycle === 'active' ? 'accepted' : $query->lifecycle;
        }
        if ($query->delivery !== 'all') {
            $clauses[] = 'd.state = :delivery';
            $parameters['delivery'] = $query->delivery;
        }
        if ($query->recordType !== 'all') {
            $clauses[] = 's.is_qualification_test = :qualification_test';
            $parameters['qualification_test'] = $query->recordType === 'qualification' ? '1' : '0';
        }

        return [' WHERE ' . implode(' AND ', $clauses), $parameters];
    }

    /** @param array<string, mixed> $row */
    private function listItem(array $row): SubmissionReviewListItem
    {
        return new SubmissionReviewListItem(
            $this->string($row, 'public_id'),
            $this->string($row, 'form_public_id'),
            $this->string($row, 'display_name'),
            $this->string($row, 'created_at'),
            $this->string($row, 'classification'),
            $this->lifecycle($this->string($row, 'state')),
            $this->delivery($row['delivery_state'] ?? null),
            $this->integer($row, 'is_qualification_test') === 1,
            $this->maskRecipient($this->string($row, 'recipient')),
        );
    }

    /** @param array<string, mixed> $row */
    private function details(PDO $connection, array $row): SubmissionReviewDetails
    {
        $fieldsJson = $this->string($row, 'fields_json');

        try {
            $decoded = json_decode($fieldsJson, true, 20, JSON_THROW_ON_ERROR);
        } catch (Throwable $failure) {
            throw new SubmissionReviewFailure('stored_data_invalid', 'This submission contains invalid stored field data. The record was preserved and its values were not displayed.', $failure);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new SubmissionReviewFailure('stored_data_invalid', 'This submission contains invalid stored field data. The record was preserved and its values were not displayed.');
        }

        $fieldStatement = $connection->prepare(
            'SELECT control_name, display_label FROM form_configuration_version_fields WHERE version_id = :version_id ORDER BY ordinal',
        );
        $fieldStatement->execute(['version_id' => $this->integer($row, 'configuration_version_id')]);
        $fields = [];

        foreach ($fieldStatement->fetchAll(PDO::FETCH_ASSOC) as $field) {
            if (!is_array($field) || !is_string($field['control_name'] ?? null) || !is_string($field['display_label'] ?? null)) {
                throw new SubmissionReviewFailure('stored_data_invalid', 'This submission has an invalid saved field definition. The record was preserved and its values were not displayed.');
            }
            $controlName = $field['control_name'];
            $missing = !array_key_exists($controlName, $decoded);
            $value = $missing ? '' : $this->fieldValue($decoded[$controlName] ?? null);
            $long = $this->length($value) > 240;
            $fields[] = new SubmissionReviewField($field['display_label'], $value, $missing, $long ? $this->preview($value) : $value, $long);
        }

        $auditStatement = $connection->prepare(
            "SELECT event_name, outcome, occurred_at FROM audit_events WHERE resource_type = 'submission' AND resource_public_id = :public_id ORDER BY occurred_at DESC, id DESC LIMIT 50",
        );
        $auditStatement->execute(['public_id' => $this->string($row, 'public_id')]);
        $auditEvents = [];
        foreach ($auditStatement->fetchAll(PDO::FETCH_ASSOC) as $event) {
            if (is_array($event) && is_string($event['event_name'] ?? null) && is_string($event['outcome'] ?? null) && is_string($event['occurred_at'] ?? null)) {
                $auditEvents[] = ['event' => $event['event_name'], 'outcome' => $event['outcome'], 'occurredAt' => $event['occurred_at']];
            }
        }

        return new SubmissionReviewDetails(
            $this->string($row, 'public_id'),
            $this->string($row, 'form_public_id'),
            $this->string($row, 'display_name'),
            $this->integer($row, 'configuration_version'),
            $this->string($row, 'page_path'),
            $this->string($row, 'form_marker'),
            $this->string($row, 'created_at'),
            $this->string($row, 'classification'),
            $this->lifecycle($this->string($row, 'state')),
            $this->delivery($row['delivery_state'] ?? null),
            $this->integer($row, 'is_qualification_test') === 1,
            $this->maskRecipient($this->string($row, 'recipient')),
            $this->string($row, 'subject'),
            $this->string($row, 'receipt_id'),
            $fields,
            $auditEvents,
            $this->nullableString($row, 'handled_at'),
            $this->nullableString($row, 'trashed_at'),
        );
    }

    /** @return array<string, mixed>|null */
    private function actionRow(PDO $connection, string $publicId): ?array
    {
        $statement = $connection->prepare(
            'SELECT s.id, s.state, s.classification, s.pre_trash_state, d.id AS delivery_id, d.state AS delivery_state '
            . 'FROM submissions s LEFT JOIN delivery_jobs d ON d.submission_id = s.id WHERE s.public_id = :public_id LIMIT 1',
        );
        $statement->execute(['public_id' => $publicId]);
        $rawRow = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($rawRow) ? $this->normalizeRow($rawRow) : null;
    }

    /** @param array<string, mixed> $row */
    private function transition(PDO $connection, array $row, string $publicId, SubmissionReviewAction $action, string $timestamp): SubmissionReviewActionResult
    {
        $state = $this->string($row, 'state');
        $classification = $this->string($row, 'classification');
        $event = 'spoke.submission.' . $action->value;

        if ($action === SubmissionReviewAction::HANDLED) {
            if ($state === 'handled') {
                return new SubmissionReviewActionResult(false, 'information', 'This submission was already marked handled. No change was made.');
            }
            if ($state !== 'accepted') {
                return $this->rejected($connection, $publicId, $event, $timestamp, 'A submission must be in active review before it can be marked handled.');
            }
            $this->update($connection, "UPDATE submissions SET state = 'handled', handled_at = :timestamp, updated_at = :timestamp WHERE id = :id AND state = 'accepted'", $row, $timestamp);

            return $this->success($connection, $publicId, $event, $timestamp, 'The submission was marked handled.');
        }

        if ($action === SubmissionReviewAction::NOT_SPAM) {
            if ($classification === 'normal') {
                return new SubmissionReviewActionResult(false, 'information', 'This submission was already classified as normal. No change was made.');
            }
            if ($state === 'trashed') {
                return $this->rejected($connection, $publicId, $event, $timestamp, 'Restore the submission before changing its classification.');
            }
            $this->update($connection, "UPDATE submissions SET classification = 'normal', updated_at = :timestamp WHERE id = :id AND classification = 'suspected_spam' AND state <> 'trashed'", $row, $timestamp);

            return $this->success($connection, $publicId, $event, $timestamp, 'The submission was marked not spam. No message was resent.');
        }

        if ($action === SubmissionReviewAction::TRASH) {
            if ($state === 'trashed') {
                return new SubmissionReviewActionResult(false, 'information', 'This submission is already in Trash. No change was made.');
            }
            $this->update($connection, "UPDATE submissions SET state = 'trashed', pre_trash_state = :pre_trash_state, trashed_at = :timestamp, updated_at = :timestamp WHERE id = :id AND state IN ('accepted', 'handled')", $row, $timestamp, ['pre_trash_state' => $state]);

            return $this->success($connection, $publicId, $event, $timestamp, 'The submission was moved to Trash. It remains recoverable until it is permanently deleted.');
        }

        if ($action === SubmissionReviewAction::RESTORE) {
            if ($state !== 'trashed') {
                return new SubmissionReviewActionResult(false, 'information', 'This submission is not in Trash. No change was made.');
            }
            $restoreState = $this->nullableString($row, 'pre_trash_state');
            if (!in_array($restoreState, ['accepted', 'handled'], true)) {
                return $this->rejected($connection, $publicId, $event, $timestamp, 'The previous lifecycle state is unavailable, so this submission cannot be restored safely.');
            }
            $this->update($connection, 'UPDATE submissions SET state = :state, pre_trash_state = NULL, trashed_at = NULL, updated_at = :timestamp WHERE id = :id AND state = \'trashed\'', $row, $timestamp, ['state' => $restoreState]);

            return $this->success($connection, $publicId, $event, $timestamp, 'The submission was restored to its previous review state.');
        }

        if ($state !== 'trashed') {
            return $this->rejected($connection, $publicId, $event, $timestamp, 'Only submissions already in Trash can be permanently deleted.');
        }

        $delivery = $this->nullableString($row, 'delivery_state');
        if ($delivery === null || in_array($delivery, ['queued', 'processing'], true)) {
            return $this->rejected($connection, $publicId, $event, $timestamp, 'This submission cannot be permanently deleted while its delivery is still queued or processing. Wait for a terminal delivery result and try again.');
        }
        if (!in_array($delivery, ['sent', 'failed', 'uncertain'], true)) {
            return $this->rejected($connection, $publicId, $event, $timestamp, 'This submission has no valid terminal delivery state, so it cannot be permanently deleted safely.');
        }

        $id = $this->integer($row, 'id');
        $this->execute($connection, 'DELETE FROM submission_attempts WHERE submission_id = :id', ['id' => $id]);
        $this->execute($connection, 'DELETE FROM delivery_attempts WHERE job_id = :delivery_id', ['delivery_id' => $this->integer($row, 'delivery_id')]);
        $this->execute($connection, 'DELETE FROM delivery_jobs WHERE submission_id = :id', ['id' => $id]);
        $this->execute($connection, 'DELETE FROM submissions WHERE id = :id AND state = \'trashed\'', ['id' => $id]);
        $this->audit($connection, 'spoke.submission.delete', 'success', $timestamp, $publicId);

        return new SubmissionReviewActionResult(true, 'success', 'The submission and its owned delivery records were permanently deleted.');
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $extra
     */
    private function update(PDO $connection, string $sql, array $row, string $timestamp, array $extra = []): void
    {
        $parameters = array_merge(['id' => $this->integer($row, 'id'), 'timestamp' => $timestamp], $extra);
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            throw new SubmissionReviewFailure('stale_state', 'The submission changed before the action was saved. Reload it and try again.');
        }
    }

    private function success(PDO $connection, string $publicId, string $event, string $timestamp, string $message): SubmissionReviewActionResult
    {
        $this->audit($connection, $event, 'success', $timestamp, $publicId);

        return new SubmissionReviewActionResult(true, 'success', $message);
    }

    private function rejected(PDO $connection, string $publicId, string $event, string $timestamp, string $message): SubmissionReviewActionResult
    {
        $this->audit($connection, $event, 'rejected', $timestamp, $publicId);

        return new SubmissionReviewActionResult(false, 'danger', $message);
    }

    private function audit(PDO $connection, string $event, string $outcome, string $timestamp, string $publicId): void
    {
        $statement = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id) VALUES (:event, :outcome, :occurred_at, \'submission\', :public_id)',
        );
        $statement->execute(['event' => $event, 'outcome' => $outcome, 'occurred_at' => $timestamp, 'public_id' => $publicId]);
    }

    /** @param array<string, mixed> $parameters */
    private function execute(PDO $connection, string $sql, array $parameters): void
    {
        $statement = $connection->prepare($sql);
        $statement->execute($parameters);
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new SubmissionReviewFailure('installation_required', 'The local installation is not initialized. Run installation before reviewing submissions.');
        }
        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
            $connection->exec('PRAGMA foreign_keys = ON');
            $connection->exec('PRAGMA busy_timeout = 5000');

            return $connection;
        } catch (Throwable $failure) {
            throw new SubmissionReviewFailure('database_unavailable', 'The local submission database is unavailable. Check private storage and try again.', $failure);
        }
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new SubmissionReviewFailure('stored_data_invalid', 'The local submission record is invalid and cannot be displayed safely.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        return $row[$key] === null ? null : $this->string($row, $key);
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !is_string($row[$key] ?? null) && !is_float($row[$key] ?? null)) {
            throw new SubmissionReviewFailure('stored_data_invalid', 'The local submission record is invalid and cannot be changed safely.');
        }

        return (int) $row[$key];
    }

    private function lifecycle(string $state): string
    {
        return match ($state) {
            'accepted' => 'active',
            'handled' => 'handled',
            'trashed' => 'trash',
            default => throw new SubmissionReviewFailure('stored_data_invalid', 'The local submission lifecycle is invalid and cannot be displayed safely.'),
        };
    }

    private function delivery(mixed $state): string
    {
        if ($state === null) {
            return 'unavailable';
        }
        if (!is_string($state) || !in_array($state, ['queued', 'processing', 'sent', 'failed', 'uncertain'], true)) {
            return 'unavailable';
        }

        return $state;
    }

    private function fieldValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            $values = [];
            foreach ($value as $part) {
                if (!is_string($part)) {
                    throw new SubmissionReviewFailure('stored_data_invalid', 'A saved submission field has an unsupported value. The record was preserved and its values were not displayed.');
                }
                $values[] = $part;
            }

            return implode(', ', $values);
        }

        throw new SubmissionReviewFailure('stored_data_invalid', 'A saved submission field has an unsupported value. The record was preserved and its values were not displayed.');
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function preview(string $value): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, 240, 'UTF-8') . '…' : substr($value, 0, 240) . '…';
    }

    private function maskRecipient(string $recipient): string
    {
        $at = strrpos($recipient, '@');
        if ($at === false || $at < 1 || $at === strlen($recipient) - 1) {
            return 'Configured recipient';
        }
        $local = substr($recipient, 0, $at);
        $domain = substr($recipient, $at + 1);
        $domainParts = explode('.', $domain);
        $maskedDomain = substr($domain, 0, 1) . str_repeat('•', max(1, strlen($domain) - 1));
        if (count($domainParts) > 1) {
            $maskedDomain = substr($domainParts[0], 0, 1) . str_repeat('•', max(1, strlen($domainParts[0]) - 1)) . '.' . end($domainParts);
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
