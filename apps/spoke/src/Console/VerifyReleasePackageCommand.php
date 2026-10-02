<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Core\Release\ReleasePackageVerifier;
use Formvex\Core\Release\ReleaseVerificationFailure;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:release:verify', description: 'Verify a production Spoke release package without changing the installation.')]
final class VerifyReleasePackageCommand extends Command
{
    public function __construct(
        private readonly ReleasePackageVerifier $verifier,
        private readonly SpokeStorageResolver $storageResolver,
    ) {
        parent::__construct();
        $this->addOption('application-root', null, InputOption::VALUE_REQUIRED, 'Private root of the local Spoke installation.');
        $this->addOption('archive', null, InputOption::VALUE_REQUIRED, 'Release ZIP to verify.');
        $this->addOption('sha256', null, InputOption::VALUE_OPTIONAL, 'Expected external SHA-256 digest.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $input->getOption('application-root');
        $archive = $input->getOption('archive');
        $expectedDigest = $input->getOption('sha256');
        if (!is_string($root) || $root === '' || !is_string($archive) || $archive === '' || ($expectedDigest !== null && !is_string($expectedDigest))) {
            $output->writeln('FAIL release_verify: Provide --application-root and --archive, with an optional --sha256 digest.');

            return Command::INVALID;
        }

        try {
            $this->storageResolver->resolve($root);
            $result = $this->verifier->verify($archive, $expectedDigest === '' ? null : $expectedDigest);
            $output->writeln(sprintf(
                'OK release_verify: version=%s schema=%s-%s files=%d. No installation state was changed.',
                $result->releaseVersion,
                $result->schemaMinimum,
                $result->schemaMaximum,
                count($result->entries),
            ));

            return Command::SUCCESS;
        } catch (ReleaseVerificationFailure $failure) {
            $output->writeln(sprintf('FAIL release_verify: %s: %s No installation state was changed.', $failure->failureCode, $failure->getMessage()));

            return Command::FAILURE;
        } catch (Throwable) {
            $output->writeln('FAIL release_verify: The release package failed safe verification. No installation state was changed.');

            return Command::FAILURE;
        }
    }
}
