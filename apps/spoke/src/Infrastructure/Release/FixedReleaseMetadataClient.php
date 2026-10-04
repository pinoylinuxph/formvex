<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataClient;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseMetadata;
use JsonException;

final readonly class FixedReleaseMetadataClient implements ReleaseMetadataClient
{
    public function __construct(private ?string $metadataUrl = null)
    {
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

        $handle = curl_init($metadataUrl);
        if ($handle === false) {
            throw new ReleaseCheckFailure('metadata_client_unavailable', 'The release metadata client could not be initialized safely.');
        }

        $body = '';
        $tooLarge = false;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Formvex-Spoke-Release-Check/1',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new ReleaseCheckFailure('metadata_response_too_large', 'The release metadata response exceeded the safe size limit.');
        }
        if ($result !== true || $error !== '') {
            throw new ReleaseCheckFailure('metadata_transport_failed', 'The release metadata endpoint could not be reached within the safe timeout.');
        }
        if ($status < 200 || $status >= 300) {
            throw new ReleaseCheckFailure('metadata_http_failed', 'The release metadata endpoint returned an unsuccessful response.');
        }
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            throw new ReleaseCheckFailure('metadata_content_type_invalid', 'The release metadata endpoint did not return JSON.');
        }
        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
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
