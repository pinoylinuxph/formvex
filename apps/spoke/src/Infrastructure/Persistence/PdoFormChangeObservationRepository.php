<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\FormChangeObservation\Contract\FormChangeObservationRepository;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservation;
use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use Throwable;

final class PdoFormChangeObservationRepository implements FormChangeObservationRepository
{
    public function record(
        PrivateStoragePaths $paths,
        string $publicId,
        string $formPublicId,
        int $configurationVersion,
        string $pagePath,
        string $formMarker,
        string $fingerprint,
        array $differences,
        DateTimeImmutable $observedAt,
    ): void {
        $connection = $this->connection($paths);
        $timestamp = $this->formatTimestamp($observedAt);

        try {
            $connection->beginTransaction();
            $existing = $connection->prepare(
                "SELECT id FROM form_change_observations
                 WHERE form_public_id = :form_public_id
                   AND configuration_version = :configuration_version
                   AND page_path = :page_path
                   AND form_marker = :form_marker
                   AND fingerprint = :fingerprint
                   AND status = 'open'
                 LIMIT 1",
            );
            $existing->execute([
                'form_public_id' => $formPublicId,
                'configuration_version' => $configurationVersion,
                'page_path' => $pagePath,
                'form_marker' => $formMarker,
                'fingerprint' => $fingerprint,
            ]);
            $existingId = $existing->fetchColumn();

            if ($existingId !== false) {
                $update = $connection->prepare(
                    "UPDATE form_change_observations
                     SET last_observed_at = :last_observed_at
                     WHERE id = :id AND status = 'open'",
                );
                $update->execute(['last_observed_at' => $timestamp, 'id' => (int) $existingId]);
            } else {
                $insert = $connection->prepare(
                    'INSERT INTO form_change_observations '
                    . '(public_id, form_public_id, configuration_version, page_path, form_marker, fingerprint, status, differences_json, first_observed_at, last_observed_at) '
                    . "VALUES (:public_id, :form_public_id, :configuration_version, :page_path, :form_marker, :fingerprint, 'open', :differences_json, :first_observed_at, :last_observed_at)",
                );
                $insert->execute([
                    'public_id' => $publicId,
                    'form_public_id' => $formPublicId,
                    'configuration_version' => $configurationVersion,
                    'page_path' => $pagePath,
                    'form_marker' => $formMarker,
                    'fingerprint' => $fingerprint,
                    'differences_json' => json_encode($differences, JSON_THROW_ON_ERROR),
                    'first_observed_at' => $timestamp,
                    'last_observed_at' => $timestamp,
                ]);
            }

            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new FormChangeObservationFailure(
                'storage_unavailable',
                'The observed form change could not be recorded safely. The visitor form was not affected.',
                $failure,
            );
        }
    }

    public function listOpen(PrivateStoragePaths $paths): array
    {
        $connection = $this->connection($paths);

        try {
            $statement = $connection->query(
                "SELECT public_id, form_public_id, configuration_version, page_path, form_marker, differences_json, first_observed_at, last_observed_at
                 FROM form_change_observations
                 WHERE status = 'open'
                 ORDER BY last_observed_at DESC, id DESC",
            );
            $items = [];

            foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
                if (!is_array($rawRow)) {
                    continue;
                }

                $row = $this->normalizeRow($rawRow);

                $differences = json_decode($this->stringValue($row, 'differences_json'), true, 10, JSON_THROW_ON_ERROR);

                if (!is_array($differences)) {
                    throw new FormChangeObservationFailure('stored_data_invalid', 'The form change warning data is invalid and cannot be displayed safely.');
                }

                $items[] = new FormChangeObservation(
                    $this->stringValue($row, 'public_id'),
                    $this->stringValue($row, 'form_public_id'),
                    $this->integerValue($row, 'configuration_version'),
                    $this->stringValue($row, 'page_path'),
                    $this->stringValue($row, 'form_marker'),
                    $this->differences($differences),
                    $this->date($this->stringValue($row, 'first_observed_at')),
                    $this->date($this->stringValue($row, 'last_observed_at')),
                );
            }

            return $items;
        } catch (FormChangeObservationFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new FormChangeObservationFailure('storage_unavailable', 'The form change warnings could not be loaded. Check the local database and try again.', $failure);
        }
    }

    public function countOpen(PrivateStoragePaths $paths): int
    {
        $connection = $this->connection($paths);

        try {
            $statement = $connection->query("SELECT COUNT(*) FROM form_change_observations WHERE status = 'open'");

            return $statement === false ? 0 : (int) $statement->fetchColumn();
        } catch (Throwable $failure) {
            throw new FormChangeObservationFailure('storage_unavailable', 'The form change warning count could not be loaded safely.', $failure);
        }
    }

    public function resolve(PrivateStoragePaths $paths, string $publicId, string $actor, DateTimeImmutable $resolvedAt): void
    {
        $this->transition($paths, $publicId, $actor, $this->formatTimestamp($resolvedAt), 'resolved');
    }

    public function discard(PrivateStoragePaths $paths, string $publicId, string $actor, DateTimeImmutable $discardedAt): void
    {
        $this->transition($paths, $publicId, $actor, $this->formatTimestamp($discardedAt), 'discarded');
    }

    private function transition(PrivateStoragePaths $paths, string $publicId, string $actor, string $timestamp, string $status): void
    {
        $connection = $this->connection($paths);

        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                "UPDATE form_change_observations
                 SET status = :status, resolved_at = :resolved_at, resolved_by = :resolved_by
                 WHERE public_id = :public_id AND status = 'open'",
            );
            $statement->execute([
                'status' => $status,
                'resolved_at' => $timestamp,
                'resolved_by' => $actor,
                'public_id' => $publicId,
            ]);

            if ($statement->rowCount() !== 1) {
                throw new FormChangeObservationFailure('not_found', 'The form change warning is no longer open. Refresh Forms and review the current warnings.');
            }

            $audit = $connection->prepare(
                'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) '
                . "VALUES (:event_name, 'success', :occurred_at, 'form_change_observation', :resource_public_id, :metadata_json)",
            );
            $audit->execute([
                'event_name' => $status === 'resolved'
                    ? 'spoke.form_change_observation.resolved'
                    : 'spoke.form_change_observation.discarded',
                'occurred_at' => $timestamp,
                'resource_public_id' => $publicId,
                'metadata_json' => json_encode(['actor' => $actor], JSON_THROW_ON_ERROR),
            ]);
            $connection->commit();
        } catch (FormChangeObservationFailure $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $failure;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new FormChangeObservationFailure('storage_unavailable', 'The form change warning could not be updated. No warning state was changed.', $failure);
        }
    }

    /**
     * @param array<int|string, mixed> $differences
     * @return list<array{kind: string, control_key: string, control_type: string}>
     */
    private function differences(array $differences): array
    {
        $result = [];

        foreach ($differences as $difference) {
            if (!is_array($difference)
                || !is_string($difference['kind'] ?? null)
                || !is_string($difference['control_key'] ?? null)
                || !is_string($difference['control_type'] ?? null)) {
                continue;
            }

            $result[] = $this->difference($difference['kind'], $difference['control_key'], $difference['control_type']);

            if (count($result) >= 100) {
                break;
            }
        }

        return $result;
    }

    /** @return array{kind: string, control_key: string, control_type: string} */
    private function difference(string $kind, string $controlKey, string $controlType): array
    {
        return [
            'kind' => substr($kind, 0, 64),
            'control_key' => substr($controlKey, 0, 120),
            'control_type' => substr($controlType, 0, 32),
        ];
    }

    /** @param array<string, mixed> $row */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new FormChangeObservationFailure('stored_data_invalid', 'The form change warning data is invalid and cannot be displayed safely.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integerValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            throw new FormChangeObservationFailure('stored_data_invalid', 'The form change warning data contains an invalid numeric value.');
        }

        return (int) $value;
    }

    /**
     * @param array<int|string, mixed> $row
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

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO(
            'sqlite:' . $paths->databaseFile(),
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $connection->exec('PRAGMA busy_timeout = 5000');

        return $connection;
    }

    private function formatTimestamp(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
