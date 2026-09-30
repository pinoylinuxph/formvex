<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Captcha;

final class CurlTurnstileHttpClient implements TurnstileHttpClient
{
    public function post(string $endpoint, string $body): TurnstileHttpResponse
    {
        $handle = curl_init($endpoint);

        if ($handle === false) {
            return new TurnstileHttpResponse(0, '', 'curl_initialization_failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $responseBody = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new TurnstileHttpResponse($status, is_string($responseBody) ? $responseBody : '', $error !== '' ? $error : null);
    }
}
