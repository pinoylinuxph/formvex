<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'formvex:admin:bootstrap', description: 'Create the single local administrator.')]
final class BootstrapAdministratorCommand extends Command
{
    public function __construct(private readonly LocalAdministratorService $administratorService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Absolute Formvex application root.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $applicationRoot = $input->getOption('application-root');

        if (!is_string($applicationRoot) || $applicationRoot === '') {
            $output->writeln('FAIL application_root_invalid: The application root is required.');

            return 1;
        }

        try {
            $result = $this->administratorService->bootstrap($applicationRoot);
        } catch (AdministratorFailure $failure) {
            $output->writeln(sprintf('FAIL %s: The administrator operation could not be completed safely.', $failure->failureCode));

            return 1;
        }

        $output->writeln('SUCCESS administrator: bootstrapped');
        $output->writeln('WARNING: save this temporary password now; it will not be shown again.');
        $output->writeln('TEMPORARY_PASSWORD: ' . $result->temporaryPassword);

        return 0;
    }
}
