<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:backup:create', description: 'Create and verify a private manual or pre-upgrade backup.')]
final class CreateBackupCommand extends Command
{
    public function __construct(private readonly BackupService $backupService)
    {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Backup kind: manual or pre_upgrade.', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $kind = $input->getOption('kind');
        if (!is_string($root) || $root === '' || !is_string($kind) || !in_array($kind, ['manual', 'pre_upgrade'], true)) {
            $output->writeln('FAIL backup_create: Provide a valid --application-root and --kind=manual|pre_upgrade.');

            return Command::INVALID;
        }
        try {
            $archive = $this->backupService->create($root, BackupKind::from($kind));
            $output->writeln(sprintf('OK backup_create: %s backup %s verified (%d bytes).', $archive->kind->label(), $archive->publicId, $archive->sizeBytes));

            return Command::SUCCESS;
        } catch (BackupFailure $failure) {
            $output->writeln('FAIL backup_create: ' . $failure->getMessage());

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL backup_create: The backup could not complete safely. Check the private installation state and try again.');

            return Command::FAILURE;
        }
    }
}
