<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Captcha;

use Formvex\Spoke\Domain\Abuse\CaptchaVerificationResult;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaVerifier;
use JsonException;

final class CurlTurnstileVerifier implements CaptchaVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly TurnstileHttpClient $httpClient)
    {
    }

    public function verify(string $secret, string $token, string $remoteIp, string $expectedHostname, string $expectedAction): CaptchaVerificationResult
    {
        $response = $this->httpClient->post(self::ENDPOINT, http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $remoteIp], '', '&', PHP_QUERY_RFC3986));

        if ($response->error !== null || $response->status < 200 || $response->status >= 300 || strlen($response->body) > 65536) {
            return CaptchaVerificationResult::unavailable($response->error !== null ? 'provider_transport_failed' : 'provider_response_unavailable');
        }

        try {
            $payload = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return CaptchaVerificationResult::unavailable('provider_response_invalid');
        }

        if (!is_array($payload) || !is_bool($payload['success'] ?? null)) {
            return CaptchaVerificationResult::unavailable('provider_response_invalid');
        }

        if (!$payload['success']) {
            return CaptchaVerificationResult::invalid('provider_token_rejected');
        }

        if (isset($payload['hostname']) && (!is_string($payload['hostname']) || strtolower(rtrim($payload['hostname'], '.')) !== strtolower(rtrim($expectedHostname, '.')))) {
            return CaptchaVerificationResult::invalid('provider_hostname_mismatch');
        }

        if (isset($payload['action']) && (!is_string($payload['action']) || $payload['action'] !== $expectedAction)) {
            return CaptchaVerificationResult::invalid('provider_action_mismatch');
        }

        return CaptchaVerificationResult::passed();
    }
}
