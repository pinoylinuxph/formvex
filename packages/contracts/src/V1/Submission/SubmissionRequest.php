<?php

declare(strict_types=1);

namespace Formvex\Contracts\V1\Submission;

final readonly class SubmissionRequest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param array<string, string|list<string>> $fields
     * @param list<SubmissionFieldShape> $fieldShape
     */
    public function __construct(
        public int $schemaVersion,
        public string $pagePath,
        public string $formMarker,
        public int $configurationVersion,
        public string $attemptId,
        public array $fields,
        public array $fieldShape,
        public ?string $honeypot = null,
        public ?string $captchaToken = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
            'schema_version' => $this->schemaVersion,
            'page_path' => $this->pagePath,
            'form_marker' => $this->formMarker,
            'configuration_version' => $this->configurationVersion,
            'attempt_id' => $this->attemptId,
            'fields' => $this->fields,
            'field_shape' => array_map(
                static fn (SubmissionFieldShape $shape): array => $shape->toArray(),
                $this->fieldShape,
            ),
        ];

        if ($this->honeypot !== null) {
            $payload['honeypot'] = $this->honeypot;
        }

        if ($this->captchaToken !== null) {
            $payload['captcha_token'] = $this->captchaToken;
        }

        return $payload;
    }
}
