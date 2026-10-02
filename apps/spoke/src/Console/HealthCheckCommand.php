<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Release\HealthCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'formvex:spoke:health:check', description: 'Check release, schema, SQLite, private-storage, and recovery health.')]
final class HealthCheckCommand extends Command
{
    public function __construct(private readonly HealthCheckService $healthCheck)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        if (!is_string($root) || $root === '') {
            $output->writeln('FAIL health_check: Provide --application-root with the private installation path.');

            return Command::INVALID;
        }
        $result = $this->healthCheck->check($root);
        $output->writeln(sprintf('%s health_check: %s', $result->healthy ? 'OK' : 'FAIL', $result->message));

        return $result->healthy ? Command::SUCCESS : Command::FAILURE;
    }
}
