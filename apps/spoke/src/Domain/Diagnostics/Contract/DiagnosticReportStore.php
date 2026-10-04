<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Diagnostics\Contract;

use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface DiagnosticReportStore
{
    public function write(PrivateStoragePaths $paths, DiagnosticReport $report, string $storageKey): int;

    public function read(PrivateStoragePaths $paths, string $storageKey): DiagnosticReport;

    public function delete(PrivateStoragePaths $paths, string $storageKey): void;
}
