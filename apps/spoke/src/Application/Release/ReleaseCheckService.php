<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Release;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckLock;
use Formvex\Spoke\Domain\Release\Contract\ReleaseCheckRepository;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMetadataClient;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
use Formvex\Spoke\Domain\Release\ReleaseCheckSettings;
use Formvex\Spoke\Domain\Release\ReleaseCheckState;
use Formvex\Spoke\Domain\Release\ReleaseMetadata;
use Formvex\Spoke\Infrastructure\Release\CurrentReleaseVersionReader;
use Throwable;

final readonly class ReleaseCheckService
{
    private const CHECK_INTERVAL_SECONDS = 86400;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private ReleaseCheckRepository $repository,
        private ReleaseMetadataClient $metadataClient,
        private ReleaseCheckLock $lock,
        private CurrentReleaseVersionReader $currentRelease,
        private Clock $clock,
    ) {
    }

    public function settings(string $applicationRoot): ReleaseCheckSettings
    {
        return $this->repository->settings($this->storageResolver->resolve($applicationRoot));
    }

    public function state(string $applicationRoot): ReleaseCheckState
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $settings = $this->repository->settings($paths);
        $currentVersion = $this->currentRelease->read();
        $storedState = $this->repository->state($paths);
        $state = $storedState->withStatus($storedState->status, $currentVersion);

        if (!$settings->enabled) {
            return ReleaseCheckState::disabled($currentVersion, $state);
        }

        return $state->status === 'disabled' ? $state->withStatus('never_checked', $currentVersion) : $state;
    }

    public function saveEnabled(string $applicationRoot, bool $enabled): void
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->now();
        $this->repository->saveSettings($paths, $enabled, $now);
        $state = $this->repository->state($paths);
        $this->repository->saveState($paths, ReleaseCheckState::disabled($this->currentRelease->read(), $state), $now);
    }

    public function acknowledge(string $applicationRoot, string $releaseVersion, string $actor): void
    {
        if (!preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/', $releaseVersion)) {
            throw new ReleaseCheckFailure('release_acknowledgement_invalid', 'The selected release acknowledgement is invalid. Refresh Maintenance and review the current release notice.');
        }

        $this->repository->acknowledge($this->storageResolver->resolve($applicationRoot), $releaseVersion, $actor, $this->now());
    }

    public function run(string $applicationRoot, bool $force = false): ReleaseCheckState
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->now();
        $settings = $this->repository->settings($paths);
        $currentVersion = $this->currentRelease->read();
        $storedState = $this->repository->state($paths);
        $existing = $storedState->withStatus($storedState->status, $currentVersion);

        if (!$settings->enabled) {
            $disabled = ReleaseCheckState::disabled($currentVersion, $existing);
            $this->repository->saveState($paths, $disabled, $now);

            return $disabled;
        }

        if (!$force && $existing->lastAttemptAt !== null && $existing->lastAttemptAt->getTimestamp() > $now->getTimestamp() - self::CHECK_INTERVAL_SECONDS) {
            return $existing;
        }

        try {
            $lock = $this->lock->acquire($paths);
        } catch (InstallationFailure $failure) {
            if ($failure->failureCode === 'release_check_in_progress') {
                return $existing->withStatus('check_in_progress', $currentVersion);
            }

            throw $failure;
        }

        try {
            $metadata = $this->metadataClient->fetch();
            $state = $this->successfulState($existing, $metadata, $currentVersion, $now);
            $this->repository->saveState($paths, $state, $now);

            return $state;
        } catch (ReleaseCheckFailure $failure) {
            return $this->saveFailure($paths, $existing, $currentVersion, $now, $failure->failureCode, $failure->getMessage());
        } catch (Throwable) {
            return $this->saveFailure($paths, $existing, $currentVersion, $now, 'metadata_check_failed', 'The release metadata check failed safely. No release state was replaced.');
        } finally {
            $lock->release();
        }
    }

    private function successfulState(ReleaseCheckState $existing, ReleaseMetadata $metadata, string $currentVersion, DateTimeImmutable $now): ReleaseCheckState
    {
        $status = !$metadata->compatible || ($currentVersion !== 'unversioned' && version_compare($metadata->minimumSupportedVersion, $currentVersion, '>'))
            ? 'incompatible'
            : (version_compare($metadata->releaseVersion, $currentVersion, '>') ? 'available' : 'current');
        $acknowledgedVersion = $existing->availableVersion === $metadata->releaseVersion && $existing->severity === $metadata->severity
            ? $existing->acknowledgedVersion
            : null;
        $acknowledgedAt = $acknowledgedVersion === null ? null : $existing->acknowledgedAt;

        return new ReleaseCheckState(
            $status,
            $currentVersion,
            version_compare($metadata->releaseVersion, $currentVersion, '>') || $currentVersion === 'unversioned' ? $metadata->releaseVersion : null,
            $metadata->severity,
            $metadata->minimumSupportedVersion,
            $metadata->releaseNotesUrl,
            $metadata->packageUrl,
            $metadata->packageSha256,
            $metadata->publishedAt,
            $now,
            $now,
            null,
            null,
            $acknowledgedVersion,
            $acknowledgedAt,
        );
    }

    private function saveFailure(\Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths, ReleaseCheckState $existing, string $currentVersion, DateTimeImmutable $now, string $code, string $message): ReleaseCheckState
    {
        $state = new ReleaseCheckState('failed', $currentVersion, $existing->availableVersion, $existing->severity, $existing->minimumSupportedVersion, $existing->releaseNotesUrl, $existing->packageUrl, $existing->packageSha256, $existing->publishedAt, $now, $existing->lastSuccessfulAt, $code, $message, $existing->acknowledgedVersion, $existing->acknowledgedAt);
        $this->repository->saveState($paths, $state, $now);

        return $state;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
