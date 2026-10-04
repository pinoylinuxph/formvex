<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Diagnostics\Contract\DiagnosticReportRepository;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportMetadata;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoDiagnosticReportRepository implements DiagnosticReportRepository
{
    public function latest(PrivateStoragePaths $paths): ?DiagnosticReportMetadata
    {
        $statement = $this->connection($paths)->query(
            "SELECT public_id, actor, storage_key, generated_at, expires_at, size_bytes, status
             FROM diagnostic_reports
             WHERE status = 'available'
             ORDER BY generated_at DESC
             LIMIT 1",
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($this->normalizeRow($row)) : null;
    }

    public function find(PrivateStoragePaths $paths, string $publicId): ?DiagnosticReportMetadata
    {
        $statement = $this->connection($paths)->prepare(
            "SELECT public_id, actor, storage_key, generated_at, expires_at, size_bytes, status
             FROM diagnostic_reports
             WHERE public_id = :public_id AND status = 'available'
             LIMIT 1",
        );
        $statement->execute(['public_id' => $publicId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->map($this->normalizeRow($row)) : null;
    }

    public function available(PrivateStoragePaths $paths): array
    {
        $statement = $this->connection($paths)->query(
            "SELECT public_id, actor, storage_key, generated_at, expires_at, size_bytes, status
             FROM diagnostic_reports
             WHERE status = 'available'
             ORDER BY generated_at ASC, public_id ASC
             LIMIT 31",
        );
        $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(array_map(
            fn (mixed $row): ?DiagnosticReportMetadata => is_array($row) ? $this->map($this->normalizeRow($row)) : null,
            $rows,
        )));
    }

    public function expired(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limit): array
    {
        $statement = $this->connection($paths)->prepare(
            "SELECT public_id, actor, storage_key, generated_at, expires_at, size_bytes, status
             FROM diagnostic_reports
             WHERE status = 'available' AND expires_at <= :expires_at
             ORDER BY expires_at ASC, public_id ASC
             LIMIT :limit",
        );
        $statement->bindValue(':expires_at', $this->timestamp($now));
        $statement->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter(array_map(
            fn (mixed $row): ?DiagnosticReportMetadata => is_array($row) ? $this->map($this->normalizeRow($row)) : null,
            $rows,
        )));
    }

    public function publish(PrivateStoragePaths $paths, DiagnosticReportMetadata $metadata, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'INSERT INTO diagnostic_reports (public_id, actor, storage_key, generated_at, expires_at, size_bytes, status, created_at) '
                . "VALUES (:public_id, :actor, :storage_key, :generated_at, :expires_at, :size_bytes, 'available', :created_at)",
            );
            $statement->execute([
                'public_id' => $metadata->publicId,
                'actor' => $metadata->actor,
                'storage_key' => $metadata->storageKey,
                'generated_at' => $this->timestamp($metadata->generatedAt),
                'expires_at' => $this->timestamp($metadata->expiresAt),
                'size_bytes' => $metadata->sizeBytes,
                'created_at' => $this->timestamp($now),
            ]);
            $this->audit($connection, 'spoke.diagnostic_report.generated', 'success', $now, $metadata->publicId, $metadata->actor);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw new RuntimeException('diagnostic_report_publish_failed', 0, $failure);
        }
    }

    public function remove(PrivateStoragePaths $paths, string $publicId, string $actor, string $outcome, DateTimeImmutable $now): void
    {
        $connection = $this->connection($paths);
        try {
            $connection->beginTransaction();
            $statement = $connection->prepare("UPDATE diagnostic_reports SET status = 'expired' WHERE public_id = :public_id AND status = 'available'");
            $statement->execute(['public_id' => $publicId]);
            if ($statement->rowCount() !== 1) {
                $connection->rollBack();
                return;
            }
            $this->audit($connection, 'spoke.diagnostic_report.expired', $outcome, $now, $publicId, $actor);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw new RuntimeException('diagnostic_report_remove_failed', 0, $failure);
        }
    }

    public function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome, string $actor, ?string $publicId, DateTimeImmutable $now): void
    {
        try {
            $connection = $this->connection($paths);
            $this->audit($connection, $eventName, $outcome, $now, $publicId, $actor);
        } catch (Throwable $failure) {
            throw new RuntimeException('diagnostic_report_audit_failed', 0, $failure);
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): DiagnosticReportMetadata
    {
        return new DiagnosticReportMetadata(
            $this->string($row, 'public_id'),
            $this->string($row, 'actor'),
            $this->string($row, 'storage_key'),
            $this->date($this->string($row, 'generated_at')),
            $this->date($this->string($row, 'expires_at')),
            $this->integer($row, 'size_bytes'),
            $this->string($row, 'status'),
        );
    }

    private function audit(PDO $connection, string $eventName, string $outcome, DateTimeImmutable $now, ?string $publicId, string $actor): void
    {
        $statement = $connection->prepare(
            'INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, resource_public_id, metadata_json) '
            . "VALUES (:event_name, :outcome, :occurred_at, 'diagnostic_report', :resource_public_id, :metadata_json)",
        );
        $statement->execute([
            'event_name' => $eventName,
            'outcome' => $outcome,
            'occurred_at' => $this->timestamp($now),
            'resource_public_id' => $publicId === null ? null : substr($publicId, -8),
            'metadata_json' => json_encode(['actor' => $actor], JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<int|string, mixed> $row
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
        return new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function timestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!is_string($row[$key] ?? null)) {
            throw new RuntimeException('diagnostic_report_data_invalid');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        if (!is_int($row[$key] ?? null) && !is_string($row[$key] ?? null)) {
            throw new RuntimeException('diagnostic_report_data_invalid');
        }

        return (int) $row[$key];
    }
}
