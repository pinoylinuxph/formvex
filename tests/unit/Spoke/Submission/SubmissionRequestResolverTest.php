<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke\Submission;

use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Http\Submission\SubmissionRequestResolver;
use PHPUnit\Framework\TestCase;

final class SubmissionRequestResolverTest extends TestCase
{
    public function testResolvesTheApprovedEnvelope(): void
    {
        $request = new SubmissionRequestResolver()->resolve((string) json_encode([
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 3,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['name' => 'Ada', 'interests' => ['research', 'engineering']],
            'field_shape' => [
                ['control_name' => 'name', 'control_type' => 'text'],
                ['control_name' => 'interests', 'control_type' => 'checkbox'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(1, $request->schemaVersion);
        self::assertSame('Ada', $request->fields['name']);
        self::assertSame(['research', 'engineering'], $request->fields['interests']);
        self::assertSame('checkbox', $request->fieldShape[1]->controlType);
    }

    public function testRejectsUnknownTopLevelProperties(): void
    {
        $this->expectFailure('request_invalid', static function (): void {
            new SubmissionRequestResolver()->resolve('{"schema_version":1,"unexpected":true}');
        });
    }

    public function testRejectsMalformedFieldShapesAndNonStringValues(): void
    {
        $this->expectFailure('request_invalid', static function (): void {
            new SubmissionRequestResolver()->resolve((string) json_encode([
                'schema_version' => 1,
                'page_path' => '/',
                'form_marker' => 'contactForm',
                'configuration_version' => 1,
                'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
                'fields' => ['name' => ['not', 3]],
                'field_shape' => [['control_name' => 'name', 'control_type' => 'text']],
            ], JSON_THROW_ON_ERROR));
        });
    }

    public function testRejectsBodiesAboveTheApprovedLimit(): void
    {
        $this->expectFailure('request_too_large', static function (): void {
            new SubmissionRequestResolver()->resolve(str_repeat('x', SubmissionRequestResolver::MAX_BODY_BYTES + 1));
        });
    }

    private function expectFailure(string $code, callable $callback): void
    {
        try {
            $callback();
            self::fail('The request should have been rejected.');
        } catch (SubmissionFailure $failure) {
            self::assertSame($code, $failure->failureCode);
        }
    }
}
