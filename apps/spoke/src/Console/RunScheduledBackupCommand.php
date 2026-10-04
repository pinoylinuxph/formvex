<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Backup\ScheduledBackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:backup:run', description: 'Run one bounded scheduled backup lifecycle step.')]
final class RunScheduledBackupCommand extends Command
{
    public function __construct(private readonly ScheduledBackupService $scheduledBackupService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        if (!is_string($root) || $root === '') {
            $output->writeln('FAIL scheduled_backup: Provide a valid --application-root.');

            return Command::INVALID;
        }
        try {
            $result = $this->scheduledBackupService->run($root);
            $output->writeln(sprintf('OK scheduled_backup: status=%s created=%d rotated=%d deferred=%d. %s', $result->status, $result->created, $result->rotated, $result->deferred, $result->message));

            return $result->status === 'failed' ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('FAIL scheduled_backup: The scheduled backup could not be evaluated safely. Check the private installation state and try again.');

            return Command::FAILURE;
        }
    }
}
