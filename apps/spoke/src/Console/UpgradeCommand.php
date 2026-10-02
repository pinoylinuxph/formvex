<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Release\ReleaseUpgradeService;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:upgrade', description: 'Verify and apply a production Spoke release package under a bounded maintenance hold.')]
final class UpgradeCommand extends Command
{
    public function __construct(private readonly ReleaseUpgradeService $releaseUpgrade)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Verified release ZIP to install.');
        $this->addOption('sha256', null, InputOption::VALUE_OPTIONAL, 'Expected external SHA-256 digest.');
        $this->addOption('drain-timeout', null, InputOption::VALUE_OPTIONAL, 'Drain timeout in seconds.', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $archive = $input->getOption('archive');
        $digest = $input->getOption('sha256');
        $timeout = $input->getOption('drain-timeout');
        if (!is_string($root) || $root === '' || !is_string($archive) || $archive === '' || ($digest !== null && !is_string($digest)) || !is_string($timeout) || filter_var($timeout, FILTER_VALIDATE_INT) === false) {
            $output->writeln('FAIL upgrade: Provide --application-root, --archive, and a valid optional --sha256 or --drain-timeout.');

            return Command::INVALID;
        }

        try {
            $result = $this->releaseUpgrade->upgrade($root, $archive, $digest === '' ? null : $digest, (int) $timeout);
            $output->writeln(sprintf('OK upgrade: release=%s schema=%s backup=%s operation=%s. Maintenance cleared only after health and scheduler checks.', $result->releaseVersion, $result->schemaVersion, $result->backupId, $result->operationId));

            return Command::SUCCESS;
        } catch (ReleaseOperationFailure $failure) {
            $output->writeln(sprintf('FAIL upgrade: %s: %s The installation remains under the release hold.', $failure->failureCode, $failure->getMessage()));

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL upgrade: The release operation did not complete safely. The installation remains under the release hold.');

            return Command::FAILURE;
        }
    }
}
