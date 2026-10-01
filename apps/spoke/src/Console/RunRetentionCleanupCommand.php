<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Retention\RunRetentionCleanup;
use Formvex\Spoke\Application\Retention\RunRetentionCleanupHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:retention:run', description: 'Remove expired local submission, temporary, audit, and operational records safely.')]
final class RunRetentionCleanupCommand extends Command
{
    public function __construct(
        private readonly RunRetentionCleanupHandler $handler,
    ) {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Maximum submission records to inspect in this invocation.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $batch = $input->getOption('batch');
            $limit = is_string($batch) && ctype_digit($batch) ? (int) $batch : 100;
            $applicationRoot = $input->getOption('application-root');
            if (!is_string($applicationRoot) || $applicationRoot === '') {
                $output->writeln('FAIL retention_cleanup: Provide --application-root with the private installation path.');

                return Command::INVALID;
            }
            $result = $this->handler->handle(new RunRetentionCleanup($applicationRoot, $limit));
            $output->writeln($result->safeSummary());

            return $result->succeeded ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL retention_cleanup: The retention task could not complete safely. Check the private database and run it again.');

            return Command::FAILURE;
        }
    }
}
