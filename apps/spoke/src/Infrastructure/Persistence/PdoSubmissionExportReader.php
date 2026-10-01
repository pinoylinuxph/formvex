<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\SubmissionExportReader;
use Formvex\Spoke\Domain\Storage\SubmissionExportData;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use PDO;

final class PdoSubmissionExportReader implements SubmissionExportReader
{
    public function read(PrivateStoragePaths $paths, SubmissionReviewQuery $query, int $limit): SubmissionExportData
    {
        $connection = $this->connection($paths);
        [$where, $parameters] = $this->where($query);
        $statement = $connection->prepare(
            'SELECT s.public_id, s.configuration_version, s.is_qualification_test, s.created_at, s.handled_at, s.trashed_at, s.restored_at, s.classification, s.state, s.fields_json, '
            . 'f.display_name, d.state AS delivery_state, s.configuration_version_id '
            . 'FROM submissions s INNER JOIN form_configurations f ON f.id = s.form_id '
            . 'LEFT JOIN delivery_jobs d ON d.submission_id = s.id ' . $where
            . ' ORDER BY s.created_at ' . ($query->sort === 'oldest' ? 'ASC' : 'DESC') . ', s.id ' . ($query->sort === 'oldest' ? 'ASC' : 'DESC')
            . ' LIMIT :limit',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        $fieldColumns = [];
        $knownColumns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $rawRow = $this->normalizeRow($rawRow);
            if (count($rows) >= $limit) {
                return new SubmissionExportData([], [], $limit + 1);
            }

            $fields = $this->fields($connection, $rawRow);
            foreach ($fields['labels'] as $key => $label) {
                if (!isset($knownColumns[$key])) {
                    $knownColumns[$key] = true;
                    $fieldColumns[] = ['key' => $key, 'label' => $label];
                }
            }
            $delivery = is_string($rawRow['delivery_state'] ?? null) ? $rawRow['delivery_state'] : 'unknown';
            $state = is_string($rawRow['state'] ?? null) ? $rawRow['state'] : 'unknown';
            $rows[] = [
                'accepted_at' => $this->string($rawRow, 'created_at'),
                'form' => $this->string($rawRow, 'display_name'),
                'configuration_version' => (string) $this->integer($rawRow, 'configuration_version'),
                'record_type' => $this->integer($rawRow, 'is_qualification_test') === 1 ? 'Qualification test' : 'Visitor submission',
                'classification' => $this->string($rawRow, 'classification') === 'suspected_spam' ? 'Suspected spam' : 'Normal',
                'lifecycle' => match ($state) {
                    'accepted' => 'Active review',
                    'handled' => 'Handled',
                    'trashed' => 'Trash',
                    default => 'Unknown',
                },
                'delivery' => ucfirst($delivery),
                'delivery_outcome' => match ($delivery) {
                    'sent' => 'Accepted by SMTP transport',
                    'failed' => 'Delivery failed',
                    'uncertain' => 'Delivery outcome uncertain',
                    'queued' => 'Queued',
                    'processing' => 'Processing',
                    default => 'Unavailable',
                },
                'handled_at' => $this->nullableString($rawRow, 'handled_at'),
                'trashed_at' => $this->nullableString($rawRow, 'trashed_at'),
                'restored_at' => $this->nullableString($rawRow, 'restored_at'),
                'fields' => $fields['values'],
            ];
        }

        return new SubmissionExportData($rows, $fieldColumns, count($rows));
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

    /**
     * @param array<string, mixed> $row
     * @return array{values: array<string, string>, labels: array<string, string>}
     */
    private function fields(PDO $connection, array $row): array
    {
        $decoded = json_decode($this->string($row, 'fields_json'), true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            return ['values' => [], 'labels' => []];
        }

        $statement = $connection->prepare(
            'SELECT control_name, display_label FROM form_configuration_version_fields WHERE version_id = :version_id ORDER BY ordinal',
        );
        $statement->execute(['version_id' => $this->integer($row, 'configuration_version_id')]);
        $values = [];
        $labels = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $definition) {
            if (!is_array($definition) || !is_string($definition['control_name'] ?? null) || !is_string($definition['display_label'] ?? null)) {
                continue;
            }
            $key = $definition['control_name'];
            $labels[$key] = 'Field: ' . $definition['display_label'];
            if (!array_key_exists($key, $decoded)) {
                $values[$key] = '';
                continue;
            }
            $value = $decoded[$key];
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
            }
            $values[$key] = is_scalar($value) ? (string) $value : '';
        }

        return ['values' => $values, 'labels' => $labels];
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');

        return $connection;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return is_int($row[$key] ?? null) || is_string($row[$key] ?? null) || is_float($row[$key] ?? null) ? (int) $row[$key] : 0;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : '';
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
}
