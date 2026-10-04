<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ReleaseMetadata
{
    public function __construct(
        public string $releaseVersion,
        public string $severity,
        public bool $compatible,
        public string $minimumSupportedVersion,
        public string $releaseNotesUrl,
        public string $packageUrl,
        public string $packageSha256,
        public DateTimeImmutable $publishedAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, string $allowedHost): self
    {
        if (($payload['schema_version'] ?? null) !== 1
            || ($payload['product'] ?? null) !== 'formvex-spoke'
            || ($payload['channel'] ?? null) !== 'community'
            || !is_string($payload['release_version'] ?? null)
            || !is_string($payload['severity'] ?? null)
            || !is_bool($payload['compatible'] ?? null)
            || !is_string($payload['minimum_supported_version'] ?? null)
            || !is_string($payload['release_notes_url'] ?? null)
            || !is_string($payload['package_url'] ?? null)
            || !is_string($payload['sha256'] ?? null)
            || !is_string($payload['published_at'] ?? null)) {
            throw new ReleaseCheckFailure('metadata_schema_invalid', 'The release metadata did not match the supported schema.');
        }

        $releaseVersion = self::version($payload['release_version']);
        $minimumSupportedVersion = self::version($payload['minimum_supported_version']);
        $severity = $payload['severity'];
        if (!in_array($severity, ['normal', 'important', 'critical'], true)) {
            throw new ReleaseCheckFailure('metadata_severity_invalid', 'The release metadata contained an unsupported severity.');
        }
        $sha256 = strtolower($payload['sha256']);
        if (!preg_match('/\A[a-f0-9]{64}\z/', $sha256)) {
            throw new ReleaseCheckFailure('metadata_digest_invalid', 'The release metadata did not contain a valid SHA-256 digest.');
        }

        $releaseNotesUrl = self::url($payload['release_notes_url'], $allowedHost);
        $packageUrl = self::url($payload['package_url'], $allowedHost);
        try {
            $publishedAt = new DateTimeImmutable($payload['published_at'], new DateTimeZone('UTC'));
        } catch (Throwable $failure) {
            throw new ReleaseCheckFailure('metadata_date_invalid', 'The release metadata contained an invalid publication date.', $failure);
        }

        return new self(
            $releaseVersion,
            $severity,
            $payload['compatible'],
            $minimumSupportedVersion,
            $releaseNotesUrl,
            $packageUrl,
            $sha256,
            $publishedAt->setTimezone(new DateTimeZone('UTC')),
        );
    }

    private static function version(string $value): string
    {
        if (strlen($value) > 32 || !preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $value)) {
            throw new ReleaseCheckFailure('metadata_version_invalid', 'The release metadata contained an invalid version.');
        }

        return $value;
    }

    private static function url(string $value, string $allowedHost): string
    {
        if (strlen($value) > 512) {
            throw new ReleaseCheckFailure('metadata_url_invalid', 'The release metadata contained an overlong link.');
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $allowedHost
            || isset($parts['user'], $parts['pass'], $parts['port'], $parts['fragment'])) {
            throw new ReleaseCheckFailure('metadata_url_invalid', 'The release metadata contained a link outside the trusted HTTPS host.');
        }

        return $value;
    }
}
