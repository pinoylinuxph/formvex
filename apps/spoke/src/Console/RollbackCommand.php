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

#[AsCommand(name: 'formvex:spoke:rollback', description: 'Restore a paired pre-upgrade backup and previously verified release package.')]
final class RollbackCommand extends Command
{
    public function __construct(private readonly ReleaseUpgradeService $releaseUpgrade)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Final verified pre-upgrade backup ZIP.');
        $this->addOption('previous-package', null, InputOption::VALUE_REQUIRED, 'Exact previously verified release ZIP.');
        $this->addOption('sha256', null, InputOption::VALUE_OPTIONAL, 'Expected SHA-256 digest for the previous package.');
        $this->addOption('drain-timeout', null, InputOption::VALUE_OPTIONAL, 'Drain timeout in seconds.', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $archive = $input->getOption('archive');
        $previousPackage = $input->getOption('previous-package');
        $digest = $input->getOption('sha256');
        $timeout = $input->getOption('drain-timeout');
        if (!is_string($root) || $root === '' || !is_string($archive) || $archive === '' || !is_string($previousPackage) || $previousPackage === '' || !is_string($digest) || !is_string($timeout) || filter_var($timeout, FILTER_VALIDATE_INT) === false) {
            $output->writeln('FAIL rollback: Provide --application-root, --archive, --previous-package, --sha256, and a valid optional --drain-timeout.');

            return Command::INVALID;
        }

        try {
            $result = $this->releaseUpgrade->rollback($root, $archive, $previousPackage, $digest === '' ? null : $digest, (int) $timeout);
            $output->writeln(sprintf('OK rollback: release=%s schema=%s checkpoint=%s operation=%s. The installation reopened only after health and scheduler checks.', $result->releaseVersion, $result->schemaVersion, $result->backupId, $result->operationId));

            return Command::SUCCESS;
        } catch (ReleaseOperationFailure $failure) {
            $output->writeln(sprintf('FAIL rollback: %s: %s The installation remains under the release hold.', $failure->failureCode, $failure->getMessage()));

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL rollback: The paired rollback did not complete safely. The installation remains under the release hold.');

            return Command::FAILURE;
        }
    }
}
