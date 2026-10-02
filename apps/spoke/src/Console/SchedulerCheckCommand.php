<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:scheduler:check', description: 'Report application-observed delivery and retention scheduler health.')]
final class SchedulerCheckCommand extends Command
{
    public function __construct(
        private readonly SpokeStorageResolver $storageResolver,
        private readonly SchedulerHealthRepository $schedulerHealth,
        private readonly Clock $clock,
    ) {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        if (!is_string($root) || $root === '') {
            $output->writeln('FAIL scheduler_check: Provide --application-root with the private installation path.');

            return Command::INVALID;
        }
        try {
            $status = $this->schedulerHealth->status($this->storageResolver->resolve($root), $this->clock->now());
            foreach ($status->jobs as $job) {
                $output->writeln(sprintf('%s scheduler_check: %s status=%s last_success=%s', $job->severity === 'success' ? 'OK' : 'WARN', $job->label, $job->status, $job->lastSuccessfulAt ?? 'none'));
            }
            $output->writeln('INFO scheduler_check: The application reports heartbeat evidence only; it cannot inspect the hosting provider crontab directly.');

            return $status->status === 'healthy' ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL scheduler_check: Scheduler health could not be read safely from private storage.');

            return Command::FAILURE;
        }
    }
}
