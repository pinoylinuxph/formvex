<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Installation;

use Formvex\Spoke\Domain\Installation\Contract\InstallationStore;
use Formvex\Spoke\Domain\Installation\Contract\PrivateStorage;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\InstallationMarker;
use Throwable;

final readonly class InitializeSpokeInstallation
{
    public function __construct(
        private RunInstallationPreflight $preflight,
        private PrivateStorage $privateStorage,
        private InstallationStore $installationStore,
    ) {
    }

    public function execute(InstallationConfiguration $configuration): InstallationOutcome
    {
        $preflight = $this->preflight->execute($configuration);

        if ($preflight->hasBlockingFailures()) {
            return InstallationOutcome::failure($preflight, 'preflight_failed');
        }

        try {
            $paths = $this->privateStorage->prepare($configuration);
            $lock = $this->privateStorage->acquireLock($paths);

            try {
                $marker = $this->privateStorage->readMarker($paths);
                $initialization = $this->installationStore->initialize($paths);

                if ($marker === null && !$initialization->created) {
                    return InstallationOutcome::failure($preflight, 'installation_incomplete');
                }

                if ($marker !== null) {
                    if (
                        $marker->installationId !== $initialization->identity->installationId
                        || $marker->schemaVersion !== $initialization->identity->schemaVersion
                    ) {
                        return InstallationOutcome::failure($preflight, 'installation_state_invalid');
                    }

                    return InstallationOutcome::success($preflight, $initialization->identity, true);
                }

                $this->privateStorage->writeMarker(
                    $paths,
                    new InstallationMarker(
                        $initialization->identity->installationId,
                        $initialization->identity->schemaVersion,
                    ),
                );

                return InstallationOutcome::success($preflight, $initialization->identity, false);
            } finally {
                $lock->release();
            }
        } catch (InstallationFailure $failure) {
            return InstallationOutcome::failure($preflight, $failure->failureCode);
        } catch (Throwable) {
            return InstallationOutcome::failure($preflight, 'internal_error');
        }
    }
}
