<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Diagnostics;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use JsonException;

final readonly class DiagnosticReport
{
    public const SCHEMA_VERSION = 1;

    public const MAX_BYTES = 131072;

    public const MAX_SECTIONS = 12;

    public const MAX_FIELDS = 256;

    /** @var list<array{key: string, label: string, status: string, entries: list<array{key: string, label: string, value: string}>}> */
    public readonly array $sections;

    /** @param array<int, mixed> $sections */
    public function __construct(
        public string $publicId,
        public DateTimeImmutable $generatedAt,
        public DateTimeImmutable $expiresAt,
        array $sections,
    ) {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $this->publicId) !== 1) {
            throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report identity is invalid.');
        }
        if (count($sections) > self::MAX_SECTIONS) {
            throw new DiagnosticReportFailure('report_too_large', 'The diagnostic report contains too many sections.');
        }
        $fields = 0;
        $normalizedSections = [];
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['key'] ?? null) || !is_string($section['label'] ?? null) || !is_string($section['status'] ?? null) || !is_array($section['entries'] ?? null)) {
                throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report contains an invalid section.');
            }
            $fields += count($section['entries']);
            $entries = [];
            foreach ($section['entries'] as $entry) {
                if (!is_array($entry) || !is_string($entry['key'] ?? null) || !is_string($entry['label'] ?? null) || !is_string($entry['value'] ?? null)) {
                    throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report contains an invalid field.');
                }
                $entries[] = ['key' => $entry['key'], 'label' => $entry['label'], 'value' => $entry['value']];
            }
            $normalizedSections[] = ['key' => $section['key'], 'label' => $section['label'], 'status' => $section['status'], 'entries' => $entries];
        }
        if ($fields > self::MAX_FIELDS) {
            throw new DiagnosticReportFailure('report_too_large', 'The diagnostic report contains too many fields.');
        }
        if ($this->expiresAt <= $this->generatedAt) {
            throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report expiry is invalid.');
        }
        $this->sections = $normalizedSections;
    }

    /** @return array{schema_version: int, report_id: string, generated_at: string, expires_at: string, sections: list<array{key: string, label: string, status: string, entries: list<array{key: string, label: string, value: string}>}>} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'report_id' => $this->publicId,
            'generated_at' => $this->timestamp($this->generatedAt),
            'expires_at' => $this->timestamp($this->expiresAt),
            'sections' => $this->sections,
        ];
    }

    public function toJson(): string
    {
        try {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        } catch (JsonException $exception) {
            throw new DiagnosticReportFailure('report_encoding_failed', 'The diagnostic report could not be encoded safely.', $exception);
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION || !is_string($data['report_id'] ?? null) || !is_string($data['generated_at'] ?? null) || !is_string($data['expires_at'] ?? null) || !is_array($data['sections'] ?? null)) {
            throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report format is invalid.');
        }

        $sections = [];
        foreach ($data['sections'] as $section) {
            if (!is_array($section) || !is_string($section['key'] ?? null) || !is_string($section['label'] ?? null) || !is_string($section['status'] ?? null) || !is_array($section['entries'] ?? null)) {
                throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report format is invalid.');
            }
            $entries = [];
            foreach ($section['entries'] as $entry) {
                if (!is_array($entry) || !is_string($entry['key'] ?? null) || !is_string($entry['label'] ?? null) || !is_string($entry['value'] ?? null)) {
                    throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report format is invalid.');
                }
                $entries[] = ['key' => $entry['key'], 'label' => $entry['label'], 'value' => $entry['value']];
            }
            $sections[] = ['key' => $section['key'], 'label' => $section['label'], 'status' => $section['status'], 'entries' => $entries];
        }

        try {
            $generatedAt = new DateTimeImmutable($data['generated_at']);
            $expiresAt = new DateTimeImmutable($data['expires_at']);
        } catch (Exception $exception) {
            throw new DiagnosticReportFailure('report_invalid', 'The diagnostic report timestamp is invalid.', $exception);
        }

        return new self(
            $data['report_id'],
            $generatedAt->setTimezone(new DateTimeZone('UTC')),
            $expiresAt->setTimezone(new DateTimeZone('UTC')),
            $sections,
        );
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
