<?php

declare(strict_types=1);

namespace Formvex\Contracts\V1\Submission;

final readonly class SubmissionFieldShape
{
    public function __construct(
        public string $controlName,
        public string $controlType,
    ) {
    }

    /** @return array{control_name: string, control_type: string} */
    public function toArray(): array
    {
        return [
            'control_name' => $this->controlName,
            'control_type' => $this->controlType,
        ];
    }
}
