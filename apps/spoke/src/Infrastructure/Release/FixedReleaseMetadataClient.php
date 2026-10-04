<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataClient;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataTransport;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseMetadata;
use JsonException;

final readonly class FixedReleaseMetadataClient implements ReleaseMetadataClient
{
    private ReleaseMetadataTransport $transport;

    public function __construct(private ?string $metadataUrl = null, ?ReleaseMetadataTransport $transport = null)
    {
        $this->transport = $transport ?? new CurlReleaseMetadataTransport();
    }

    public function fetch(): ReleaseMetadata
    {
        $metadataUrl = $this->metadataUrl ?? '';
        if ($metadataUrl === '') {
            throw new ReleaseCheckFailure('metadata_endpoint_unconfigured', 'The fixed release metadata endpoint is not configured for this installation.');
        }

        $parts = parse_url($metadataUrl);
        $host = is_array($parts) && is_string($parts['host'] ?? null) ? $parts['host'] : null;
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || $host === null || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            throw new ReleaseCheckFailure('metadata_endpoint_invalid', 'The fixed release metadata endpoint must be an HTTPS URL without credentials, a port, or a fragment.');
        }

        $response = $this->transport->fetch($metadataUrl);
        if ($response->tooLarge) {
            throw new ReleaseCheckFailure('metadata_response_too_large', 'The release metadata response exceeded the safe size limit.');
        }
        if ($response->failureCode !== null) {
            $message = $response->failureCode === 'metadata_client_unavailable'
                ? 'The release metadata client could not be initialized safely.'
                : 'The release metadata endpoint could not be reached within the safe timeout.';
            throw new ReleaseCheckFailure($response->failureCode, $message);
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw new ReleaseCheckFailure('metadata_http_failed', 'The release metadata endpoint returned an unsuccessful response.');
        }
        if ($response->contentType !== '' && !str_contains(strtolower($response->contentType), 'application/json')) {
            throw new ReleaseCheckFailure('metadata_content_type_invalid', 'The release metadata endpoint did not return JSON.');
        }
        try {
            $payload = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $failure) {
            throw new ReleaseCheckFailure('metadata_json_invalid', 'The release metadata response was not valid JSON.', $failure);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new ReleaseCheckFailure('metadata_schema_invalid', 'The release metadata response must be a JSON object.');
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return ReleaseMetadata::fromPayload($normalized, strtolower($host));
    }
}
