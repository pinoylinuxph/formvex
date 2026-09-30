<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Delivery\RunDeliveryWorker;
use Formvex\Spoke\Application\Delivery\RunDeliveryWorkerHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'formvex:spoke:delivery:run', description: 'Process due Spoke email delivery jobs through the configured SMTP account.')]
final class RunDeliveryWorkerCommand extends Command
{
    public function __construct(private readonly RunDeliveryWorkerHandler $handler)
    {
        parent::__construct();
        $this->addOption('batch', null, InputOption::VALUE_OPTIONAL, 'Maximum jobs to process in this invocation.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = $input->getOption('batch');
        $limit = is_string($batch) && ctype_digit($batch) ? (int) $batch : 10;
        $result = $this->handler->handle(new RunDeliveryWorker($limit));
        $output->writeln($result->safeSummary());

        return $result->succeeded ? Command::SUCCESS : Command::FAILURE;
    }
}
