<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\FormDiscovery\Contract\FormDiscoveryStore;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryCandidate;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryCandidateStatus;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryForm;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryPayload;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use Throwable;

final class PdoFormDiscoveryStore implements FormDiscoveryStore
{
    public function createCapability(
        PrivateStoragePaths $paths,
        string $capabilityId,
        string $tokenHash,
        string $sessionHash,
        PageIdentity $page,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $expiresAt,
    ): void {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'INSERT INTO form_discovery_capabilities '
            . '(capability_id, token_hash, session_hash, host, path, created_at, expires_at) '
            . 'VALUES (:capability_id, :token_hash, :session_hash, :host, :path, :created_at, :expires_at)',
        );
        $statement->execute([
            'capability_id' => $capabilityId,
            'token_hash' => $tokenHash,
            'session_hash' => $sessionHash,
            'host' => $page->host,
            'path' => $page->path,
            'created_at' => $this->formatTimestamp($createdAt),
            'expires_at' => $this->formatTimestamp($expiresAt),
        ]);
    }

    public function redeemAndCreateCandidate(
        PrivateStoragePaths $paths,
        string $tokenHash,
        PageIdentity $page,
        string $candidateId,
        DiscoveryPayload $payload,
        DateTimeImmutable $now,
    ): DiscoveryCandidate {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'SELECT * FROM form_discovery_capabilities '
                . 'WHERE token_hash = :token_hash AND host = :host AND path = :path LIMIT 1',
            );
            $statement->execute([
                'token_hash' => $tokenHash,
                'host' => $page->host,
                'path' => $page->path,
            ]);
            $capability = $statement->fetch(PDO::FETCH_ASSOC);

            if (!is_array($capability)) {
                throw new FormDiscoveryFailure('discovery_not_authorized', 'This discovery authorization is invalid for the selected HTTPS page. Start a new discovery session from the Forms portal.');
            }

            $expiresAt = $this->timestamp($capability['expires_at'] ?? null);

            if ($expiresAt <= $now) {
                throw new FormDiscoveryFailure('discovery_expired', 'The discovery authorization expired. Start a new discovery session from the Forms portal.');
            }

            $attemptCount = $this->integer($capability['attempt_count'] ?? null);
            $metadataAccepted = $this->integer($capability['metadata_accepted'] ?? null) === 1;

            if ($metadataAccepted || $attemptCount >= 2) {
                throw new FormDiscoveryFailure('discovery_replayed', 'This discovery authorization has already been used. Start a new discovery session from the Forms portal.');
            }

            $update = $connection->prepare(
                'UPDATE form_discovery_capabilities SET attempt_count = attempt_count + 1, metadata_accepted = 1, redeemed_at = :redeemed_at WHERE id = :id AND attempt_count < 2 AND metadata_accepted = 0',
            );
            $update->execute([
                'redeemed_at' => $this->formatTimestamp($now),
                'id' => $this->integer($capability['id'] ?? null),
            ]);

            if ($update->rowCount() !== 1) {
                throw new FormDiscoveryFailure('discovery_replayed', 'This discovery authorization has already been used. Start a new discovery session from the Forms portal.');
            }

            $payloadJson = json_encode($payload->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $insert = $connection->prepare(
                'INSERT INTO form_discovery_candidates '
                . '(candidate_id, capability_id, session_hash, host, path, schema_version, status, payload_json, created_at, expires_at, updated_at) '
                . 'VALUES (:candidate_id, :capability_id, :session_hash, :host, :path, :schema_version, :status, :payload_json, :created_at, :expires_at, :updated_at)',
            );
            $timestamp = $this->formatTimestamp($now);
            $insert->execute([
                'candidate_id' => $candidateId,
                'capability_id' => $this->integer($capability['id'] ?? null),
                'session_hash' => $this->string($capability['session_hash'] ?? null),
                'host' => $page->host,
                'path' => $page->path,
                'schema_version' => DiscoveryPayload::SCHEMA_VERSION,
                'status' => DiscoveryCandidateStatus::PENDING->value,
                'payload_json' => $payloadJson,
                'created_at' => $timestamp,
                'expires_at' => $this->formatTimestamp($expiresAt),
                'updated_at' => $timestamp,
            ]);
            $connection->commit();

            return new DiscoveryCandidate($candidateId, $page->host, $page->path, DiscoveryPayload::SCHEMA_VERSION, DiscoveryCandidateStatus::PENDING, $payload->forms, $now, $expiresAt);
        } catch (FormDiscoveryFailure $failure) {
            $this->rollback($connection);
            throw $failure;
        } catch (Throwable) {
            $this->rollback($connection);
            throw new FormDiscoveryFailure('discovery_save_failed', 'Formvex could not save the discovery result. No form draft was changed. Start a new discovery session and try again.');
        }
    }

    public function listCandidates(PrivateStoragePaths $paths, string $sessionHash, DateTimeImmutable $now): array
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'SELECT * FROM form_discovery_candidates WHERE session_hash = :session_hash AND expires_at > :now AND status = :status ORDER BY created_at DESC',
        );
        $statement->execute([
            'session_hash' => $sessionHash,
            'now' => $this->formatTimestamp($now),
            'status' => DiscoveryCandidateStatus::PENDING->value,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = $this->hydrate($this->row($row));
        }

        return $candidates;
    }

    public function findCandidate(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): ?DiscoveryCandidate
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'SELECT * FROM form_discovery_candidates WHERE session_hash = :session_hash AND candidate_id = :candidate_id AND expires_at > :now LIMIT 1',
        );
        $statement->execute([
            'session_hash' => $sessionHash,
            'candidate_id' => $candidateId,
            'now' => $this->formatTimestamp($now),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($this->row($row)) : null;
    }

    public function markCandidateApplied(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): void
    {
        $this->mark($paths, $sessionHash, $candidateId, DiscoveryCandidateStatus::APPLIED, $now);
    }

    public function markCandidateDiscarded(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DateTimeImmutable $now): void
    {
        $this->mark($paths, $sessionHash, $candidateId, DiscoveryCandidateStatus::DISCARDED, $now);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): DiscoveryCandidate
    {
        try {
            $payload = json_decode($this->string($row['payload_json'] ?? null), true, 20, JSON_THROW_ON_ERROR);

            if (!is_array($payload)) {
                throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid saved discovery candidate.');
            }

            $forms = $payload['forms'] ?? [];

            if (!is_array($forms) || !array_is_list($forms)) {
                throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid saved discovery form list.');
            }

            $normalizedForms = array_map(static function (mixed $form): DiscoveryForm {
                if (!is_array($form) || array_is_list($form)) {
                    throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid saved discovery form.');
                }

                $formObject = [];

                foreach ($form as $key => $value) {
                    if (!is_string($key)) {
                        throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found invalid saved discovery form properties.');
                    }

                    $formObject[$key] = $value;
                }

                return DiscoveryForm::fromArray($formObject);
            }, $forms);

            return new DiscoveryCandidate(
                $this->string($row['candidate_id'] ?? null),
                $this->string($row['host'] ?? null),
                $this->string($row['path'] ?? null),
                $this->integer($row['schema_version'] ?? null),
                DiscoveryCandidateStatus::from($this->string($row['status'] ?? null)),
                $normalizedForms,
                $this->timestamp($row['created_at'] ?? null),
                $this->timestamp($row['expires_at'] ?? null),
            );
        } catch (FormDiscoveryFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid saved discovery candidate.');
        }
    }

    private function mark(PrivateStoragePaths $paths, string $sessionHash, string $candidateId, DiscoveryCandidateStatus $status, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        $statement = $connection->prepare(
            'UPDATE form_discovery_candidates SET status = :status, updated_at = :updated_at WHERE session_hash = :session_hash AND candidate_id = :candidate_id AND status = :pending',
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $this->formatTimestamp($now),
            'session_hash' => $sessionHash,
            'candidate_id' => $candidateId,
            'pending' => DiscoveryCandidateStatus::PENDING->value,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new FormDiscoveryFailure('discovery_candidate_unavailable', 'The discovery candidate is no longer available. Return to Forms and start a new discovery session.');
        }
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        if (!is_file($paths->databaseFile())) {
            throw new FormDiscoveryFailure('installation_required', 'Formvex is not initialized. Run the installation command before starting discovery.');
        }

        try {
            $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $connection->exec('PRAGMA foreign_keys = ON');

            return $connection;
        } catch (Throwable) {
            throw new FormDiscoveryFailure('database_unavailable', 'Formvex could not access its local discovery storage. Check the private application storage and try again.');
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
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function timestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid discovery timestamp.');
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid discovery timestamp.');
        }
    }

    private function integer(mixed $value): int
    {
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid discovery number.');
        }

        return (int) $value;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid discovery value.');
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found an invalid saved discovery row.');
        }

        $row = [];

        foreach ($value as $key => $property) {
            if (!is_string($key)) {
                throw new FormDiscoveryFailure('discovery_state_invalid', 'Formvex found invalid saved discovery columns.');
            }

            $row[$key] = $property;
        }

        return $row;
    }
}
