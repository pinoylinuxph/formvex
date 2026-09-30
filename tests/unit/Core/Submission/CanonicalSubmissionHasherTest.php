<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Core\Submission;

use Formvex\Core\Submission\CanonicalSubmissionHasher;
use PHPUnit\Framework\TestCase;

final class CanonicalSubmissionHasherTest extends TestCase
{
    public function testObjectPropertyOrderDoesNotChangeTheHash(): void
    {
        $hasher = new CanonicalSubmissionHasher();

        self::assertSame(
            $hasher->hash(['fields' => ['email' => 'ada@example.test', 'name' => 'Ada'], 'schema_version' => 1]),
            $hasher->hash(['schema_version' => 1, 'fields' => ['name' => 'Ada', 'email' => 'ada@example.test']]),
        );
    }

    public function testArrayOrderDoesChangeTheHash(): void
    {
        $hasher = new CanonicalSubmissionHasher();

        self::assertNotSame(
            $hasher->hash(['values' => ['engineering', 'support']]),
            $hasher->hash(['values' => ['support', 'engineering']]),
        );
    }

    public function testUnicodeStringsRemainStable(): void
    {
        $hasher = new CanonicalSubmissionHasher();

        self::assertSame(
            $hasher->hash(['message' => 'Mabuhay, 世界']),
            $hasher->hash(['message' => 'Mabuhay, 世界']),
        );
    }
}
