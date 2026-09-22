<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

final class CurlWebExposureVerifier implements WebExposureVerifier
{
    public function isDenied(string $url): bool
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return false;
        }

        $responseBytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$responseBytes): int {
                $responseBytes += strlen($chunk);

                return $responseBytes <= 65536 ? strlen($chunk) : 0;
            },
        ]);

        curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        curl_close($handle);

        return $error === 0 && in_array($status, [403, 404], true);
    }
}
