<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\FormChangeObservation\FormChangeObservationFailure;
use Formvex\Spoke\Http\FormChangeObservation\FormChangeObservationRequestResolver;
use PHPUnit\Framework\TestCase;

final class FormChangeObservationRequestResolverTest extends TestCase
{
    public function testItAcceptsStructureOnlyMetadataAndRejectsUnknownProperties(): void
    {
        $request = new FormChangeObservationRequestResolver()->resolve(json_encode([
            'schema_version' => 1,
            'configuration_version' => 2,
            'page_path' => '/contact',
            'form_marker' => 'contactForm',
            'source_fingerprint' => str_repeat('a', 64),
            'controls' => [[
                'control_name' => 'topic',
                'control_type' => 'select',
                'required' => true,
                'max_length' => 80,
                'choice_values' => ['support'],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(2, $request->configurationVersion);
        self::assertSame('topic', $request->controls[0]['control_name']);

        try {
            new FormChangeObservationRequestResolver()->resolve(json_encode([
                'schema_version' => 1,
                'configuration_version' => 2,
                'page_path' => '/contact',
                'form_marker' => 'contactForm',
                'controls' => [],
                'visitor_value' => 'must-not-be-accepted',
            ], JSON_THROW_ON_ERROR));
            self::fail('Unknown visitor data must be rejected.');
        } catch (FormChangeObservationFailure $failure) {
            self::assertSame('request_invalid', $failure->failureCode);
        }
    }
}
