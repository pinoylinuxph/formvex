<?php

declare(strict_types=1);

namespace Formvex\Contracts\V1\Submission;

final readonly class SubmissionAcceptedResponse
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $receiptId,
        public string $acknowledgement,
    ) {
    }

    /** @return array{schema_version: int, receipt_id: string, acknowledgement: string} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_id' => $this->receiptId,
            'acknowledgement' => $this->acknowledgement,
        ];
    }
}
