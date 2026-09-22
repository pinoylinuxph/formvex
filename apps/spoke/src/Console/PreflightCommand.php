<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Installation\RunInstallationPreflight;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:preflight', description: 'Check whether a Spoke can be initialized safely.')]
final class PreflightCommand extends Command
{
    public function __construct(
        private readonly RunInstallationPreflight $preflight,
        private readonly InstallationOutput $installationOutput,
    ) {
        parent::__construct();

        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Absolute Formvex application root.');
        $this->addOption('web-root', null, InputOption::VALUE_REQUIRED, 'Absolute public web root.');
        $this->addOption('public-base-url', null, InputOption::VALUE_OPTIONAL, 'HTTPS public base URL when web verification is required.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $configuration = InstallationConfiguration::fromOperatorInput(
                $this->requiredOption($input, 'application-root'),
                $this->requiredOption($input, 'web-root'),
                $this->optionalOption($input, 'public-base-url'),
            );
            $report = $this->preflight->execute($configuration);
            $this->installationOutput->renderPreflight($output, $report);

            return $report->hasBlockingFailures() ? 1 : 0;
        } catch (Throwable) {
            $output->writeln('FAIL internal_error: Preflight could not be completed safely.');

            return 2;
        }
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The %s option is required.', $name));
        }

        return $value;
    }

    private function optionalOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s option is invalid.', $name));
        }

        return $value;
    }
}
