<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Diagnostics;

use DateTimeImmutable;

final readonly class DiagnosticReportGeneration
{
    public function __construct(
        public DiagnosticReport $report,
        public bool $created,
        public ?DateTimeImmutable $availableAt,
    ) {
    }
}
