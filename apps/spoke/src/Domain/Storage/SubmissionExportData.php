<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage;

final readonly class SubmissionExportData
{
    /**
     * @param list<array{accepted_at: string, form: string, configuration_version: string, record_type: string, classification: string, lifecycle: string, delivery: string, delivery_outcome: string, handled_at: string, trashed_at: string, restored_at: string, fields: array<string, string>}> $rows
     * @param list<array{key: string, label: string}> $fieldColumns
     */
    public function __construct(
        public array $rows,
        public array $fieldColumns,
        public int $total,
    ) {
    }
}
