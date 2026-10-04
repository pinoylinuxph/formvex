<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseMetadata;
use PHPUnit\Framework\TestCase;

final class ReleaseMetadataTest extends TestCase
{
    public function testStrictCommunityMetadataIsAcceptedAndNormalised(): void
    {
        $metadata = ReleaseMetadata::fromPayload($this->payload(), 'updates.example.com');

        self::assertSame('1.1.0', $metadata->releaseVersion);
        self::assertSame('important', $metadata->severity);
        self::assertSame('https://updates.example.com/releases/1.1.0', $metadata->releaseNotesUrl);
        self::assertSame(str_repeat('a', 64), $metadata->packageSha256);
    }

    public function testLinksMustRemainOnTheFixedHttpsHost(): void
    {
        $payload = $this->payload();
        $payload['package_url'] = 'https://cdn.example.com/formvex.zip';

        $this->expectException(ReleaseCheckFailure::class);
        $this->expectExceptionMessage('outside the trusted HTTPS host');
        ReleaseMetadata::fromPayload($payload, 'updates.example.com');
    }

    public function testUnsafeOrUnexpectedMetadataIsRejected(): void
    {
        $payload = $this->payload();
        $payload['sha256'] = 'not-a-digest';

        try {
            ReleaseMetadata::fromPayload($payload, 'updates.example.com');
            self::fail('Invalid metadata must not be accepted.');
        } catch (ReleaseCheckFailure $failure) {
            self::assertSame('metadata_digest_invalid', $failure->failureCode);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'product' => 'formvex-spoke',
            'channel' => 'community',
            'release_version' => '1.1.0',
            'severity' => 'important',
            'compatible' => true,
            'minimum_supported_version' => '1.0.0',
            'release_notes_url' => 'https://updates.example.com/releases/1.1.0',
            'package_url' => 'https://updates.example.com/packages/formvex-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
            'published_at' => '2026-10-04T00:00:00Z',
        ];
    }
}
