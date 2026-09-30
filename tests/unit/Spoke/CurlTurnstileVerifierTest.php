<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Abuse\CaptchaVerificationResult;
use Formvex\Spoke\Infrastructure\Captcha\CurlTurnstileVerifier;
use Formvex\Spoke\Infrastructure\Captcha\TurnstileHttpClient;
use Formvex\Spoke\Infrastructure\Captcha\TurnstileHttpResponse;
use PHPUnit\Framework\TestCase;

final class CurlTurnstileVerifierTest extends TestCase
{
    public function testSuccessfulProviderResponseRequiresExpectedHostnameAndActionWhenPresent(): void
    {
        $client = new FakeTurnstileHttpClient(new TurnstileHttpResponse(200, json_encode([
            'success' => true,
            'hostname' => 'logoslab.xyz',
            'action' => 'formvex',
        ], JSON_THROW_ON_ERROR)));
        $result = new CurlTurnstileVerifier($client)->verify('secret', 'token', '203.0.113.10', 'logoslab.xyz', 'formvex');

        self::assertSame(CaptchaVerificationResult::PASSED, $result->status);
        self::assertStringContainsString('secret=secret', $client->body);
        self::assertStringContainsString('response=token', $client->body);
    }

    public function testActionMismatchAndMalformedOrUnavailableResponsesFailSafely(): void
    {
        $mismatch = new FakeTurnstileHttpClient(new TurnstileHttpResponse(200, '{"success":true,"action":"other"}'));
        self::assertSame('provider_action_mismatch', new CurlTurnstileVerifier($mismatch)->verify('secret', 'token', '203.0.113.10', 'logoslab.xyz', 'formvex')->reasonCode);

        $malformed = new FakeTurnstileHttpClient(new TurnstileHttpResponse(200, '{'));
        self::assertSame('provider_response_invalid', new CurlTurnstileVerifier($malformed)->verify('secret', 'token', '203.0.113.10', 'logoslab.xyz', 'formvex')->reasonCode);

        $unavailable = new FakeTurnstileHttpClient(new TurnstileHttpResponse(0, '', 'timeout'));
        self::assertSame('provider_transport_failed', new CurlTurnstileVerifier($unavailable)->verify('secret', 'token', '203.0.113.10', 'logoslab.xyz', 'formvex')->reasonCode);
    }
}

final class FakeTurnstileHttpClient implements TurnstileHttpClient
{
    public string $body = '';

    public function __construct(private readonly TurnstileHttpResponse $response)
    {
    }

    public function post(string $endpoint, string $body): TurnstileHttpResponse
    {
        $this->body = $body;

        return $this->response;
    }
}
