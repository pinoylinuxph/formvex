<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Backup\RestoreService;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:backup:verify', description: 'Validate a private backup archive without changing live state.')]
final class VerifyBackupCommand extends Command
{
    public function __construct(private readonly RestoreService $restoreService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Private backup archive to validate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $archive = $input->getOption('archive');
        if (!is_string($root) || $root === '' || !is_string($archive) || $archive === '') {
            $output->writeln('FAIL backup_verify: Provide --application-root and --archive.');

            return Command::INVALID;
        }
        try {
            $schema = $this->restoreService->validate($root, $archive);
            $output->writeln('OK backup_verify: The archive is valid for schema ' . $schema . '.');

            return Command::SUCCESS;
        } catch (BackupFailure $failure) {
            $output->writeln('FAIL backup_verify: ' . $failure->getMessage());

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL backup_verify: The archive could not be validated safely.');

            return Command::FAILURE;
        }
    }
}
