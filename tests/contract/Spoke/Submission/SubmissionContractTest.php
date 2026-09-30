<?php

declare(strict_types=1);

namespace Formvex\Tests\Contract\Spoke\Submission;

use Formvex\Contracts\V1\Submission\SubmissionAcceptedResponse;
use Formvex\Contracts\V1\Submission\SubmissionErrorResponse;
use Formvex\Contracts\V1\Submission\SubmissionFieldError;
use Formvex\Contracts\V1\Submission\SubmissionFieldShape;
use Formvex\Contracts\V1\Submission\SubmissionRequest;
use PHPUnit\Framework\TestCase;

final class SubmissionContractTest extends TestCase
{
    public function testRequestAndAcceptedResponseUseTheVersionedPublicShape(): void
    {
        $request = new SubmissionRequest(
            1,
            '/',
            'contactForm',
            3,
            '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
            ['message' => 'hello'],
            [new SubmissionFieldShape('message', 'textarea')],
        );
        $accepted = new SubmissionAcceptedResponse('0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 'Your message has been received.');

        self::assertSame(1, $request->toArray()['schema_version']);
        self::assertSame('contactForm', $request->toArray()['form_marker']);
        self::assertSame(['message' => 'hello'], $request->toArray()['fields']);
        self::assertSame([
            'schema_version' => 1,
            'receipt_id' => '0195f2b8-7c3a-7f42-8c11-4ac3b865e092',
            'acknowledgement' => 'Your message has been received.',
        ], $accepted->toArray());
    }

    public function testErrorResponseContainsOnlyBoundedFieldGuidanceAndRequestId(): void
    {
        $response = new SubmissionErrorResponse(
            'field_validation_failed',
            'Please correct the highlighted fields and try again.',
            '0195f2b8-7c3a-7f42-8c11-4ac3b865e092',
            [new SubmissionFieldError('email', 'email_invalid', 'Enter a valid email address.')],
        );

        self::assertSame([
            'schema_version' => 1,
            'error' => [
                'code' => 'field_validation_failed',
                'message' => 'Please correct the highlighted fields and try again.',
                'fields' => [[
                    'field' => 'email',
                    'code' => 'email_invalid',
                    'message' => 'Enter a valid email address.',
                ]],
            ],
            'request_id' => '0195f2b8-7c3a-7f42-8c11-4ac3b865e092',
        ], $response->toArray());
    }
}
