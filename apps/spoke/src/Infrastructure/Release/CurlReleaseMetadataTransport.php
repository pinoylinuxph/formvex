<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataTransport;
use Formvex\Spoke\Domain\Release\ReleaseMetadataTransportResponse;

final class CurlReleaseMetadataTransport implements ReleaseMetadataTransport
{
    public function fetch(string $metadataUrl): ReleaseMetadataTransportResponse
    {
        $handle = curl_init($metadataUrl);
        if ($handle === false) {
            return new ReleaseMetadataTransportResponse('', 0, '', 'metadata_client_unavailable');
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

        return new ReleaseMetadataTransportResponse(
            $body,
            $status,
            $contentType,
            $result !== true || $error !== '' ? 'metadata_transport_failed' : null,
            $tooLarge,
        );
    }
}
