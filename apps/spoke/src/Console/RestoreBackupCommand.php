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

#[AsCommand(name: 'formvex:spoke:restore', description: 'Validate or restore a private backup archive from the server.')]
final class RestoreBackupCommand extends Command
{
    public function __construct(private readonly RestoreService $restoreService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('web-root', null, InputOption::VALUE_REQUIRED, 'Configured public web root (documented operator input).');
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Private backup archive to validate or restore.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate the archive without replacing live state.');
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm replacement of live state.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $webRoot = $input->getOption('web-root');
        $archive = $input->getOption('archive');
        if (!is_string($root) || $root === '' || !is_string($webRoot) || $webRoot === '' || !is_string($archive) || $archive === '') {
            $output->writeln('FAIL restore: Provide --application-root, --web-root, and --archive.');

            return Command::INVALID;
        }
        try {
            if (!$input->getOption('confirm')) {
                $schema = $this->restoreService->validate($root, $archive);
                $output->writeln('OK restore: Dry-run validation passed for schema ' . $schema . '. No live state was changed.');

                return Command::SUCCESS;
            }
            $output->writeln('WARNING restore: live state replacement is starting; the installation will remain unavailable if verification fails.');
            $output->writeln('OK restore: ' . $this->restoreService->restore($root, $archive, true));

            return Command::SUCCESS;
        } catch (BackupFailure $failure) {
            $output->writeln('FAIL restore: ' . $failure->getMessage());

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL restore: Restore could not complete safely. The installation may remain unavailable for server-side repair.');

            return Command::FAILURE;
        }
    }
}
