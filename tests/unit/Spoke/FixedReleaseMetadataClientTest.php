<?php

declare(strict_types=1);

namespace FormvexTests\Unit\Spoke;

use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataTransport;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseMetadataTransportResponse;
use Formvex\Spoke\Infrastructure\Release\FixedReleaseMetadataClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FixedReleaseMetadataClientTest extends TestCase
{
    #[DataProvider('invalidEndpointProvider')]
    public function testEndpointMustBeFixedHttpsWithoutCredentialsPortOrFragment(string $endpoint): void
    {
        try {
            new FixedReleaseMetadataClient($endpoint)->fetch();
            self::fail('An unsafe endpoint must be rejected before network access.');
        } catch (ReleaseCheckFailure $failure) {
            self::assertSame('metadata_endpoint_invalid', $failure->failureCode);
        }
    }

    public function testMissingEndpointFailsClosedWithoutNetworkAccess(): void
    {
        try {
            new FixedReleaseMetadataClient()->fetch();
            self::fail('A missing endpoint must not trigger a network request.');
        } catch (ReleaseCheckFailure $failure) {
            self::assertSame('metadata_endpoint_unconfigured', $failure->failureCode);
        }
    }

    public function testTimeoutFailsWithSafeTransportError(): void
    {
        $this->assertFailure(
            new ReleaseMetadataTransportResponse('', 0, '', 'metadata_transport_failed'),
            'metadata_transport_failed',
        );
    }

    public function testUnavailableSourceFailsWithSafeTransportError(): void
    {
        $this->assertFailure(
            new ReleaseMetadataTransportResponse('', 0, '', 'metadata_client_unavailable'),
            'metadata_client_unavailable',
        );
    }

    #[DataProvider('unsuccessfulStatusProvider')]
    public function testRedirectsAndHttpErrorsAreRejectedWithoutFollowingThem(int $status): void
    {
        $this->assertFailure(
            new ReleaseMetadataTransportResponse('', $status, 'application/json'),
            'metadata_http_failed',
        );
    }

    public function testOversizedResponseIsRejectedBeforeParsing(): void
    {
        $this->assertFailure(
            new ReleaseMetadataTransportResponse('', 200, 'application/json', null, true),
            'metadata_response_too_large',
        );
    }

    public function testValidResponseIsParsedAfterTransportChecks(): void
    {
        $client = new FixedReleaseMetadataClient(
            'https://updates.example.com/releases.json',
            new ReleaseMetadataTestTransport(new ReleaseMetadataTransportResponse($this->validPayload(), 200, 'application/json; charset=utf-8')),
        );

        $metadata = $client->fetch();

        self::assertSame('1.1.0', $metadata->releaseVersion);
        self::assertSame('normal', $metadata->severity);
    }

    public static function unsuccessfulStatusProvider(): iterable
    {
        yield 'redirect' => [302];
        yield 'bad gateway' => [502];
        yield 'not found' => [404];
    }

    private function assertFailure(ReleaseMetadataTransportResponse $response, string $failureCode): void
    {
        try {
            new FixedReleaseMetadataClient(
                'https://updates.example.com/releases.json',
                new ReleaseMetadataTestTransport($response),
            )->fetch();
            self::fail('The metadata response must be rejected safely.');
        } catch (ReleaseCheckFailure $failure) {
            self::assertSame($failureCode, $failure->failureCode);
        }
    }

    private function validPayload(): string
    {
        return json_encode([
            'schema_version' => 1,
            'product' => 'formvex-spoke',
            'channel' => 'community',
            'release_version' => '1.1.0',
            'severity' => 'normal',
            'compatible' => true,
            'minimum_supported_version' => '1.0.0',
            'release_notes_url' => 'https://updates.example.com/releases/1.1.0-notes.txt',
            'package_url' => 'https://updates.example.com/packages/formvex-spoke-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
            'published_at' => '2026-10-04T00:00:00Z',
        ], JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEndpointProvider(): iterable
    {
        yield 'plain http' => ['http://updates.example.com/releases.json'];
        yield 'explicit port' => ['https://updates.example.com:443/releases.json'];
        yield 'username' => ['https://admin@updates.example.com/releases.json'];
        yield 'password' => ['https://:secret@updates.example.com/releases.json'];
        yield 'fragment' => ['https://updates.example.com/releases.json#latest'];
    }
}

final class ReleaseMetadataTestTransport implements ReleaseMetadataTransport
{
    public function __construct(private readonly ReleaseMetadataTransportResponse $response)
    {
    }

    public function fetch(string $metadataUrl): ReleaseMetadataTransportResponse
    {
        return $this->response;
    }
}
