<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Installation\InstallationOutcome;
use Formvex\Spoke\Domain\Installation\PreflightReport;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class InstallationOutput
{
    public function renderPreflight(OutputInterface $output, PreflightReport $report): void
    {
        foreach ($report->checks as $check) {
            $output->writeln(sprintf('%s %s: %s', strtoupper($check->status->value), $check->code, $check->message));
        }
    }

    public function renderOutcome(OutputInterface $output, InstallationOutcome $outcome): void
    {
        $this->renderPreflight($output, $outcome->preflight);

        if (!$outcome->successful) {
            $output->writeln(sprintf('FAIL installation: %s', $outcome->failureCode ?? 'internal_error'));

            return;
        }

        $state = $outcome->alreadyInitialized ? 'already_initialized' : 'initialized';
        $output->writeln(sprintf('SUCCESS installation: %s', $state));

        if ($outcome->identity !== null) {
            $output->writeln(sprintf('SCHEMA_VERSION: %s', $outcome->identity->schemaVersion));
        }
    }
}
