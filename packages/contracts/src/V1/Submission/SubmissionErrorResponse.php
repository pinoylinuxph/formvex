<?php

declare(strict_types=1);

namespace Formvex\Contracts\V1\Submission;

final readonly class SubmissionErrorResponse
{
    /**
     * @param list<SubmissionFieldError> $fields
     */
    public function __construct(
        public string $code,
        public string $message,
        public string $requestId,
        public array $fields = [],
    ) {
    }

    /** @return array{schema_version: int, error: array{code: string, message: string, fields?: list<array{field: string, code: string, message: string}>}, request_id: string} */
    public function toArray(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->fields !== []) {
            $error['fields'] = array_map(
                static fn (SubmissionFieldError $field): array => $field->toArray(),
                $this->fields,
            );
        }

        return [
            'schema_version' => SubmissionAcceptedResponse::SCHEMA_VERSION,
            'error' => $error,
            'request_id' => $this->requestId,
        ];
    }
}
