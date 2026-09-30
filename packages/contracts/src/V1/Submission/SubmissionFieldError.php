<?php

declare(strict_types=1);

namespace Formvex\Contracts\V1\Submission;

final readonly class SubmissionFieldError
{
    public function __construct(
        public string $field,
        public string $code,
        public string $message,
    ) {
    }

    /** @return array{field: string, code: string, message: string} */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
