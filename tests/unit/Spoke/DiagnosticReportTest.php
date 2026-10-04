<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportFailure;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportTest extends TestCase
{
    public function testReportRoundTripsAsSafeJson(): void
    {
        $generated = new DateTimeImmutable('2026-10-05T00:00:00.000000Z', new DateTimeZone('UTC'));
        $report = new DiagnosticReport(
            '01a0f744-d824-7576-ac66-c5f492429fd1',
            $generated,
            $generated->modify('+15 minutes'),
            [[
                'key' => 'health',
                'label' => 'Health',
                'status' => 'Available',
                'entries' => [['key' => 'status', 'label' => 'Status', 'value' => 'Clear']],
            ]],
        );

        $decoded = json_decode($report->toJson(), true, 8, JSON_THROW_ON_ERROR);
        $restored = DiagnosticReport::fromArray($decoded);

        self::assertSame($report->publicId, $restored->publicId);
        self::assertSame('Clear', $restored->sections[0]['entries'][0]['value']);
        self::assertSame(1, $decoded['schema_version']);
    }

    public function testReportRejectsMoreThanApprovedFieldBoundary(): void
    {
        $entries = [];
        for ($index = 0; $index <= DiagnosticReport::MAX_FIELDS; $index++) {
            $entries[] = ['key' => 'field-' . $index, 'label' => 'Field ' . $index, 'value' => 'safe'];
        }

        $this->expectException(DiagnosticReportFailure::class);
        $this->expectExceptionMessage('too many fields');
        new DiagnosticReport(
            '01a0f744-d824-7576-ac66-c5f492429fd1',
            new DateTimeImmutable('2026-10-05T00:00:00Z'),
            new DateTimeImmutable('2026-10-05T00:15:00Z'),
            [['key' => 'large', 'label' => 'Large', 'status' => 'Available', 'entries' => $entries]],
        );
    }
}
