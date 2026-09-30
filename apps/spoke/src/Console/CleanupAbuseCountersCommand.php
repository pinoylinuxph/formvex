<?php

declare(strict_types=1);

namespace Formvex\Spoke\Console;

use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Abuse\Contract\RateLimitStore;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'formvex:spoke:abuse-cleanup', description: 'Remove expired Formvex abuse-control counter buckets.')]
final class CleanupAbuseCountersCommand extends Command
{
    public function __construct(
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly AbuseSettingsStore $settingsStore,
        private readonly RateLimitStore $rateLimitStore,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            $deleted = $this->rateLimitStore->cleanup($paths, $this->settingsStore->get($paths), $this->clock->now());
            $output->writeln('OK abuse_cleanup: removed ' . $deleted . ' expired counter bucket(s).');

            return Command::SUCCESS;
        } catch (Throwable) {
            $output->writeln('FAIL abuse_cleanup: Formvex could not clean expired abuse-control counters safely.');

            return Command::FAILURE;
        }
    }
}
