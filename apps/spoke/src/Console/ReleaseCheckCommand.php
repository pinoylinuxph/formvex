<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Release\ReleaseCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:release:check', description: 'Check the fixed trusted release metadata source once.')]
final class ReleaseCheckCommand extends Command
{
    public function __construct(private readonly ReleaseCheckService $releaseCheckService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run even when the daily check interval has not elapsed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        if (!is_string($root) || $root === '') {
            $output->writeln('FAIL release_check: Provide --application-root with the private installation path.');

            return Command::INVALID;
        }

        try {
            $state = $this->releaseCheckService->run($root, (bool) $input->getOption('force'));
            $output->writeln(sprintf('OK release_check: status=%s current=%s available=%s.', $state->status, $state->currentVersion, $state->availableVersion ?? 'none'));

            return in_array($state->status, ['failed', 'check_in_progress'], true) ? Command::FAILURE : Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('FAIL release_check: The release metadata check could not be completed safely. Review the fixed endpoint and private installation state.');

            return Command::FAILURE;
        }
    }
}
