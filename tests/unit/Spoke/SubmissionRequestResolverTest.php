<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Http\Submission\SubmissionRequestResolver;
use PHPUnit\Framework\TestCase;

final class SubmissionRequestResolverTest extends TestCase
{
    public function testOptionalAbusePropertiesAreBoundedAndRemainSeparate(): void
    {
        $request = new SubmissionRequestResolver()->resolve((string) json_encode([
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 3,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['message' => 'Hello'],
            'field_shape' => [['control_name' => 'message', 'control_type' => 'textarea']],
            'honeypot' => '',
            'captcha_token' => 'provider-token',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('', $request->honeypot);
        self::assertSame('provider-token', $request->captchaToken);
        self::assertSame(['message' => 'Hello'], $request->fields);
    }

    public function testUnknownAndOversizedAbusePropertiesAreRejected(): void
    {
        $base = [
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 3,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['message' => 'Hello'],
            'field_shape' => [['control_name' => 'message', 'control_type' => 'textarea']],
        ];
        $resolver = new SubmissionRequestResolver();

        try {
            $resolver->resolve((string) json_encode([...$base, 'classification' => 'suspected_spam'], JSON_THROW_ON_ERROR));
            self::fail('Unknown client-controlled classification must be rejected.');
        } catch (SubmissionFailure $failure) {
            self::assertSame('request_invalid', $failure->failureCode);
        }

        $this->expectException(SubmissionFailure::class);
        $resolver->resolve((string) json_encode([...$base, 'captcha_token' => str_repeat('x', 4097)], JSON_THROW_ON_ERROR));
    }
}
