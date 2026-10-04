<?php

declare(strict_types=1);

namespace FormvexTests\Unit\Spoke;

use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
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
