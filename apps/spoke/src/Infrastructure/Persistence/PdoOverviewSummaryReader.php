<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Overview\Contract\OverviewSummaryReader;
use Formvex\Spoke\Domain\Overview\DeliveryOverviewCounts;
use Formvex\Spoke\Domain\Overview\FormOverviewCounts;
use Formvex\Spoke\Domain\Overview\SubmissionOverviewCounts;
use PDO;
use RuntimeException;

final class PdoOverviewSummaryReader implements OverviewSummaryReader
{
    public function forms(PrivateStoragePaths $paths): FormOverviewCounts
    {
        $connection = $this->connection($paths);
        $this->requireTables($connection, ['form_configurations', 'form_configuration_versions', 'form_configuration_evidence']);
        $statement = $connection->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN latest.state = 'active' THEN 1 ELSE 0 END), 0) AS active,
                    COALESCE(SUM(CASE WHEN latest.state = 'disabled'
                                      OR (latest.state = 'active' AND COALESCE(e.end_to_end_status, 'not_run') <> 'sent')
                                      THEN 1 ELSE 0 END), 0) AS needs_attention
             FROM form_configurations f
             LEFT JOIN form_configuration_versions latest
               ON latest.form_id = f.id
              AND latest.state <> 'draft'
              AND latest.version_number = (
                  SELECT MAX(candidate.version_number)
                  FROM form_configuration_versions candidate
                  WHERE candidate.form_id = f.id AND candidate.state <> 'draft'
              )
             LEFT JOIN form_configuration_evidence e ON e.version_id = latest.id
             WHERE f.deleted_at IS NULL",
        );

        return $this->formCounts($statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC));
    }

    public function submissions(PrivateStoragePaths $paths): SubmissionOverviewCounts
    {
        $connection = $this->connection($paths);
        $this->requireTables($connection, ['submissions']);
        $statement = $connection->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN state = 'accepted' THEN 1 ELSE 0 END), 0) AS unhandled,
                    COALESCE(SUM(CASE WHEN classification = 'suspected_spam' THEN 1 ELSE 0 END), 0) AS suspected_spam
             FROM submissions
             WHERE state <> 'trashed'",
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        return new SubmissionOverviewCounts(
            $this->integer($row, 'total'),
            $this->integer($row, 'unhandled'),
            $this->integer($row, 'suspected_spam'),
        );
    }

    public function delivery(PrivateStoragePaths $paths): DeliveryOverviewCounts
    {
        $connection = $this->connection($paths);
        $this->requireTables($connection, ['delivery_jobs']);
        $statement = $connection->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN state = 'queued' THEN 1 ELSE 0 END), 0) AS queued,
                    COALESCE(SUM(CASE WHEN state = 'processing' THEN 1 ELSE 0 END), 0) AS processing,
                    COALESCE(SUM(CASE WHEN state = 'sent' THEN 1 ELSE 0 END), 0) AS sent,
                    COALESCE(SUM(CASE WHEN state = 'failed' THEN 1 ELSE 0 END), 0) AS failed,
                    COALESCE(SUM(CASE WHEN state = 'uncertain' THEN 1 ELSE 0 END), 0) AS uncertain
             FROM delivery_jobs",
        );
        $row = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);

        return new DeliveryOverviewCounts(
            $this->integer($row, 'total'),
            $this->integer($row, 'queued'),
            $this->integer($row, 'processing'),
            $this->integer($row, 'sent'),
            $this->integer($row, 'failed'),
            $this->integer($row, 'uncertain'),
        );
    }

    private function connection(PrivateStoragePaths $paths): PDO
    {
        return new PDO('sqlite:' . $paths->databaseFile(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** @param list<string> $tables */
    private function requireTables(PDO $connection, array $tables): void
    {
        foreach ($tables as $table) {
            $statement = $connection->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $statement->execute(['table' => $table]);

            if ($statement->fetchColumn() === false) {
                throw new RuntimeException('The overview source is unavailable until the local database migration is applied.');
            }
        }
    }

    /** @param mixed $row */
    private function formCounts(mixed $row): FormOverviewCounts
    {
        return new FormOverviewCounts(
            $this->integer($row, 'total'),
            $this->integer($row, 'active'),
            $this->integer($row, 'needs_attention'),
        );
    }

    /** @param mixed $row */
    private function integer(mixed $row, string $key): int
    {
        if (!is_array($row) || !is_int($row[$key] ?? null) && !is_float($row[$key] ?? null) && !is_string($row[$key] ?? null)) {
            throw new RuntimeException('The overview source returned invalid aggregate data.');
        }

        return max(0, (int) $row[$key]);
    }
}
