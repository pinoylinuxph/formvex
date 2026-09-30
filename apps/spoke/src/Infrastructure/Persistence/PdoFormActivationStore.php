<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\FormActivation\Contract\FormActivationStore;
use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Domain\FormActivation\FormActivationEvidence;
use Formvex\Spoke\Domain\FormActivation\QualificationSession;
use Formvex\Spoke\Domain\FormActivation\QualificationStatus;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use PDOStatement;
use Throwable;
use ValueError;

final class PdoFormActivationStore implements FormActivationStore
{
    public function createCapability(
        PrivateStoragePaths $paths,
        string $capabilityId,
        string $capabilityTokenHash,
        string $sessionHash,
        FormConfigurationRecord $version,
        string $evidenceFingerprint,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void {
        $statement = $this->connection($paths)->prepare(
            'INSERT INTO form_qualification_capabilities '
            . '(capability_id, capability_token_hash, session_hash, public_form_id, version_number, host, path, form_marker, evidence_fingerprint, created_at, expires_at) '
            . 'VALUES (:capability_id, :capability_token_hash, :session_hash, :public_form_id, :version_number, :host, :path, :form_marker, :evidence_fingerprint, :created_at, :expires_at)',
        );
        $statement->execute([
            'capability_id' => $capabilityId,
            'capability_token_hash' => $capabilityTokenHash,
            'session_hash' => $sessionHash,
            'public_form_id' => $version->publicId,
            'version_number' => $version->versionNumber,
            'host' => $version->page->host,
            'path' => $version->page->path,
            'form_marker' => $version->page->formMarker,
            'evidence_fingerprint' => $evidenceFingerprint,
            'created_at' => $this->formatTimestamp($createdAt),
            'expires_at' => $this->formatTimestamp($expiresAt),
        ]);
    }

    public function redeemCapability(
        PrivateStoragePaths $paths,
        string $capabilityTokenHash,
        PageIdentity $page,
        array $formMarkers,
        string $qualificationId,
        string $qualificationTokenHash,
        DateTimeImmutable $qualificationExpiresAt,
        DateTimeImmutable $now,
    ): QualificationSession {
        $connection = $this->connection($paths);

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $statement = $connection->prepare('SELECT * FROM form_qualification_capabilities WHERE capability_token_hash = :token_hash LIMIT 1');
            $statement->execute(['token_hash' => $capabilityTokenHash]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row)) {
                throw new FormActivationFailure('qualification_not_authorized', 'This qualification authorization is invalid for the selected HTTPS page. Start a new qualification session from the Forms portal.');
            }

            if ($this->timestamp($row['expires_at'] ?? null) <= $now) {
                throw new FormActivationFailure('qualification_expired', 'The qualification authorization expired. Start a new qualification session from the Forms portal.');
            }

            if (($row['redeemed_at'] ?? null) !== null) {
                throw new FormActivationFailure('qualification_replayed', 'This qualification authorization has already been used. Start a new qualification session from the Forms portal.');
            }

            if ($this->string($row['host'] ?? null) !== $page->host || $this->string($row['path'] ?? null) !== $page->path) {
                throw new FormActivationFailure('qualification_not_authorized', 'This qualification authorization is not valid for the selected HTTPS page. Start a new qualification session from the Forms portal.');
            }

            $formMarker = $this->string($row['form_marker'] ?? null);

            if (!in_array($formMarker, $formMarkers, true)) {
                throw new FormActivationFailure('qualification_form_not_found', 'The selected page did not contain the configured form marker. Check the installed script and form marker, then start a new qualification session.');
            }

            $update = $connection->prepare(
                'UPDATE form_qualification_capabilities SET redeemed_at = :redeemed_at, qualification_id = :qualification_id, qualification_token_hash = :qualification_token_hash, qualification_expires_at = :qualification_expires_at WHERE id = :id AND redeemed_at IS NULL',
            );
            $update->execute([
                'redeemed_at' => $this->formatTimestamp($now),
                'qualification_id' => $qualificationId,
                'qualification_token_hash' => $qualificationTokenHash,
                'qualification_expires_at' => $this->formatTimestamp($qualificationExpiresAt),
                'id' => $this->integer($row['id'] ?? null),
            ]);

            if ($update->rowCount() !== 1) {
                throw new FormActivationFailure('qualification_replayed', 'This qualification authorization has already been used. Start a new qualification session from the Forms portal.');
            }

            $connection->commit();

            return new QualificationSession(
                $qualificationId,
                $this->string($row['public_form_id'] ?? null),
                $this->integer($row['version_number'] ?? null),
                PageIdentity::fromInput($page->host, $page->path, $formMarker),
                $this->string($row['evidence_fingerprint'] ?? null),
                $now,
                $qualificationExpiresAt,
            );
        } catch (FormActivationFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormActivationFailure('qualification_save_failed', 'The qualification authorization could not be safely redeemed. Start a new qualification session and try again.');
        }
    }

    public function findSessionByToken(PrivateStoragePaths $paths, string $qualificationTokenHash, DateTimeImmutable $now): ?QualificationSession
    {
        $statement = $this->connection($paths)->prepare('SELECT * FROM form_qualification_capabilities WHERE qualification_token_hash = :token_hash LIMIT 1');
        $statement->execute(['token_hash' => $qualificationTokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = $this->timestamp($row['qualification_expires_at'] ?? null);

        if ($expiresAt <= $now) {
            return null;
        }

        return new QualificationSession(
            $this->string($row['qualification_id'] ?? $row['capability_id'] ?? null),
            $this->string($row['public_form_id'] ?? null),
            $this->integer($row['version_number'] ?? null),
            PageIdentity::fromInput($this->string($row['host'] ?? null), $this->string($row['path'] ?? null), $this->string($row['form_marker'] ?? null)),
            $this->string($row['evidence_fingerprint'] ?? null),
            $this->timestamp($row['created_at'] ?? null),
            $expiresAt,
            ($row['submitted_at'] ?? null) === null ? null : $this->timestamp($row['submitted_at']),
            ($row['submission_id'] ?? null) === null ? null : (string) $this->integer($row['submission_id']),
        );
    }

    public function recordAccepted(PrivateStoragePaths $paths, string $qualificationId, string $submissionId, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare(
            'UPDATE form_qualification_capabilities SET submitted_at = :submitted_at, submission_id = :submission_id WHERE qualification_id = :qualification_id AND submitted_at IS NULL',
        );
        $statement->execute([
            'submitted_at' => $this->formatTimestamp($now),
            'submission_id' => $submissionId,
            'qualification_id' => $qualificationId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new FormActivationFailure('qualification_replayed', 'This qualification session has already submitted a test. Start a new qualification session before trying again.');
        }
    }

    public function qualificationStatus(PrivateStoragePaths $paths, string $qualificationId, DateTimeImmutable $now): ?QualificationStatus
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'SELECT q.expires_at, q.submitted_at, d.state '
            . 'FROM form_qualification_capabilities q '
            . 'LEFT JOIN submissions s ON s.qualification_id = q.qualification_id '
            . 'LEFT JOIN delivery_jobs d ON d.submission_id = s.id '
            . 'WHERE q.qualification_id = :qualification_id LIMIT 1',
        );
        $statement->execute(['qualification_id' => $qualificationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        if (($row['state'] ?? null) === 'sent') {
            return QualificationStatus::SENT;
        }

        if (($row['state'] ?? null) === 'failed') {
            return QualificationStatus::FAILED;
        }

        if (($row['state'] ?? null) === 'uncertain') {
            return QualificationStatus::UNCERTAIN;
        }

        if (($row['submitted_at'] ?? null) !== null) {
            return QualificationStatus::ACCEPTED;
        }

        if ($this->timestamp($row['expires_at'] ?? null) <= $now) {
            return QualificationStatus::STALE;
        }

        return QualificationStatus::NOT_RUN;
    }

    public function evidence(PrivateStoragePaths $paths, int $versionId, int $versionNumber, DateTimeImmutable $now): FormActivationEvidence
    {
        $statement = $this->connection($paths)->prepare('SELECT * FROM form_configuration_evidence WHERE version_id = :version_id LIMIT 1');
        $statement->execute(['version_id' => $versionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return FormActivationEvidence::empty($versionNumber, $now);
        }

        try {
            $status = QualificationStatus::from($this->string($row['end_to_end_status'] ?? 'not_run'));
        } catch (ValueError) {
            throw new FormActivationFailure('activation_state_invalid', 'The saved activation evidence has an invalid qualification status. Repair the local installation before activating this form.');
        }

        return new FormActivationEvidence(
            $versionNumber,
            $this->integer($row['evidence_revision'] ?? null),
            ($row['smtp_test_revision'] ?? null) === null ? null : $this->integer($row['smtp_test_revision']),
            $status,
            ($row['end_to_end_failure_code'] ?? null) === null ? null : $this->string($row['end_to_end_failure_code']),
            ($row['end_to_end_fingerprint'] ?? null) === null ? null : $this->string($row['end_to_end_fingerprint']),
            ($row['end_to_end_accepted_at'] ?? null) === null ? null : $this->timestamp($row['end_to_end_accepted_at']),
            ($row['end_to_end_delivery_accepted_at'] ?? null) === null ? null : $this->timestamp($row['end_to_end_delivery_accepted_at']),
            ($row['end_to_end_qualification_id'] ?? null) === null ? null : $this->string($row['end_to_end_qualification_id']),
            $this->timestamp($row['updated_at'] ?? null),
        );
    }

    public function recordEvidenceAccepted(PrivateStoragePaths $paths, int $versionId, string $fingerprint, string $qualificationId, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare(
            "UPDATE form_configuration_evidence SET end_to_end_status = 'accepted', end_to_end_failure_code = NULL, end_to_end_fingerprint = :fingerprint, end_to_end_accepted_at = :accepted_at, end_to_end_delivery_accepted_at = NULL, end_to_end_qualification_id = :qualification_id, updated_at = :updated_at WHERE version_id = :version_id",
        );
        $statement->execute([
            'fingerprint' => $fingerprint,
            'accepted_at' => $this->formatTimestamp($now),
            'qualification_id' => $qualificationId,
            'updated_at' => $this->formatTimestamp($now),
            'version_id' => $versionId,
        ]);
        $this->assertEvidenceUpdated($statement);
    }

    public function recordEvidenceSent(PrivateStoragePaths $paths, int $versionId, string $qualificationId, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare(
            "UPDATE form_configuration_evidence SET end_to_end_status = 'sent', end_to_end_failure_code = NULL, end_to_end_delivery_accepted_at = :sent_at, updated_at = :updated_at WHERE version_id = :version_id AND end_to_end_status = 'accepted'",
        );
        $statement->execute([
            'sent_at' => $this->formatTimestamp($now),
            'updated_at' => $this->formatTimestamp($now),
            'version_id' => $versionId,
        ]);
    }

    public function recordEvidenceOutcome(PrivateStoragePaths $paths, int $versionId, string $qualificationId, QualificationStatus $status, string $failureCode, DateTimeImmutable $now): void
    {
        if (!in_array($status, [QualificationStatus::FAILED, QualificationStatus::UNCERTAIN], true)) {
            throw new FormActivationFailure('activation_state_invalid', 'The qualification outcome is not a supported failure state. The form remains inactive.');
        }

        $statement = $this->connection($paths)->prepare(
            "UPDATE form_configuration_evidence SET end_to_end_status = :status, end_to_end_failure_code = :failure_code, end_to_end_delivery_accepted_at = NULL, updated_at = :updated_at WHERE version_id = :version_id AND end_to_end_qualification_id = :qualification_id AND end_to_end_status = 'accepted'",
        );
        $statement->execute([
            'status' => $status->value,
            'failure_code' => $failureCode,
            'updated_at' => $this->formatTimestamp($now),
            'version_id' => $versionId,
            'qualification_id' => $qualificationId,
        ]);
        $this->assertEvidenceUpdated($statement);
    }

    public function invalidateEvidence(PrivateStoragePaths $paths, int $versionId, string $reason, DateTimeImmutable $now): void
    {
        $statement = $this->connection($paths)->prepare(
            "UPDATE form_configuration_evidence SET evidence_revision = evidence_revision + 1, end_to_end_status = 'stale', end_to_end_failure_code = :reason, end_to_end_fingerprint = NULL, end_to_end_accepted_at = NULL, end_to_end_delivery_accepted_at = NULL, end_to_end_qualification_id = NULL, updated_at = :updated_at WHERE version_id = :version_id",
        );
        $statement->execute([
            'reason' => $reason,
            'updated_at' => $this->formatTimestamp($now),
            'version_id' => $versionId,
        ]);
    }

    private function assertEvidenceUpdated(PDOStatement $statement): void
    {
        if ($statement->rowCount() !== 1) {
            throw new FormActivationFailure('activation_state_invalid', 'The activation evidence could not be saved. The form remains inactive.');
        }
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new FormActivationFailure('installation_required', 'The local installation is not initialized. Run the installation command before activating a form.');
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
            throw new FormActivationFailure('database_unavailable', 'The activation storage is unavailable. Check the private application storage and try again.');
        }
    }

    private function rollback(PDO $connection): void
    {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new FormActivationFailure('activation_state_invalid', 'The saved activation timestamp is invalid.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new FormActivationFailure('activation_state_invalid', 'The saved activation timestamp is invalid.');
        }
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new FormActivationFailure('activation_state_invalid', 'The saved activation number is invalid.');
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new FormActivationFailure('activation_state_invalid', 'The saved activation value is invalid.');
        }

        return $value;
    }
}
