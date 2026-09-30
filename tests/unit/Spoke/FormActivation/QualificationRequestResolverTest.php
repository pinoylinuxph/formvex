<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke\FormActivation;

use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Http\FormActivation\QualificationRequestResolver;
use PHPUnit\Framework\TestCase;

final class QualificationRequestResolverTest extends TestCase
{
    public function testExtractsTheQualificationTokenWithoutPassingItToSubmissionValidation(): void
    {
        $resolved = new QualificationRequestResolver()->resolve((string) json_encode([
            'qualification_token' => 'one-time-token',
            'schema_version' => 1,
            'page_path' => '/',
            'form_marker' => 'contactForm',
            'configuration_version' => 1,
            'attempt_id' => '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            'fields' => ['message' => 'Synthetic qualification message'],
            'field_shape' => [['control_name' => 'message', 'control_type' => 'textarea']],
        ], JSON_THROW_ON_ERROR));

        self::assertSame('one-time-token', $resolved['token']);
        self::assertStringNotContainsString('qualification_token', $resolved['submissionBody']);
        self::assertStringContainsString('Synthetic qualification message', $resolved['submissionBody']);
    }

    public function testRejectsMissingOrUnknownQualificationEnvelopeProperties(): void
    {
        $resolver = new QualificationRequestResolver();

        try {
            $resolver->resolve('{"schema_version":1}');
            self::fail('A missing qualification token must be rejected.');
        } catch (FormActivationFailure $failure) {
            self::assertSame('qualification_not_authorized', $failure->failureCode);
        }

        try {
            $resolver->resolve('{"qualification_token":"token","unexpected":true}');
            self::fail('An invalid submission envelope must be rejected by the downstream resolver.');
        } catch (FormActivationFailure $failure) {
            self::assertSame('qualification_request_invalid', $failure->failureCode);
        }
    }
}
