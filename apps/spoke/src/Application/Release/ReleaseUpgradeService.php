<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Release;

use DateInterval;
use DateTimeImmutable;
use Formvex\Core\Release\ReleasePackageVerifier;
use Formvex\Core\Release\ReleaseVerificationFailure;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\FinalBackupCreator;
use Formvex\Spoke\Domain\Release\Contract\PairedCheckpointRestorer;
use Formvex\Spoke\Domain\Release\Contract\ReleaseHealthChecker;
use Formvex\Spoke\Domain\Release\Contract\ReleaseMigrationApplier;
use Formvex\Spoke\Domain\Release\Contract\ReleasePackagePublisher;
use Formvex\Spoke\Domain\Release\Contract\ReleaseSchedulerReadiness;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use Formvex\Spoke\Domain\Release\Contract\UpgradeOperationLock;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use Formvex\Spoke\Domain\Release\ReleaseOperationResult;
use Throwable;

final readonly class ReleaseUpgradeService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private ReleasePackageVerifier $packageVerifier,
        private UpgradeMaintenanceStore $maintenanceStore,
        private UpgradeOperationLock $operationLock,
        private UpgradeInFlightTracker $inFlightTracker,
        private FinalBackupCreator $finalBackupCreator,
        private BackupArchiveStore $archiveStore,
        private PairedCheckpointRestorer $checkpointRestorer,
        private ReleasePackagePublisher $packagePublisher,
        private ReleaseMigrationApplier $migrationApplier,
        private ReleaseHealthChecker $healthChecker,
        private ReleaseSchedulerReadiness $schedulerReadiness,
        private Clock $clock,
        private IdentifierGenerator $identifierGenerator,
        private string $projectRoot,
    ) {
    }

    public function upgrade(string $applicationRoot, string $archivePath, ?string $expectedSha256, int $drainTimeoutSeconds = 300): ReleaseOperationResult
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $verification = $this->verifyPackage($archivePath, $expectedSha256);
        $currentSchema = $this->archiveStore->currentSchemaVersion($paths);
        $this->assertSchemaCompatible($currentSchema, $verification->schemaMinimum, $verification->schemaMaximum);
        $lock = $this->operationLock->acquire($paths);
        $operationId = $this->identifierGenerator->uuidV7($this->clock->now());
        $staging = null;

        try {
            $now = $this->clock->now();
            $deadline = $now->add(new DateInterval('PT' . max(0, min(3600, $drainTimeoutSeconds)) . 'S'));
            $this->inFlightTracker->startDraining($paths);
            $state = $this->maintenanceStore->begin(
                $paths,
                $operationId,
                $this->currentRelease(),
                $verification->releaseVersion,
                $currentSchema,
                $verification->schemaMaximum,
                $now,
                $deadline,
            );
            $this->waitForDrain($paths, $deadline);

            $state = $this->maintenanceStore->transition($paths, $state, 'final_backup');
            $backup = $this->finalBackupCreator->create($applicationRoot, $paths);
            if ($backup->schemaVersion !== $currentSchema) {
                throw new ReleaseOperationFailure('pre_upgrade_backup_mismatch', 'The final pre-upgrade backup does not match the current schema. Code publication and migration were not completed.');
            }

            $state = $this->maintenanceStore->transition($paths, $state, 'staging');
            $staging = $this->packagePublisher->stage($archivePath, $operationId, $paths->runtime);
            $state = $this->maintenanceStore->transition($paths, $state, 'publishing');
            $this->packagePublisher->publish($staging, $this->projectRoot);
            $state = $this->maintenanceStore->transition($paths, $state, 'migrating');
            $migratedSchema = $this->migrationApplier->apply($paths);
            if ($migratedSchema !== $verification->schemaMaximum) {
                throw new ReleaseOperationFailure('migration_schema_mismatch', 'The migrated schema does not match the verified release manifest. The installation remains unavailable.');
            }

            $state = $this->maintenanceStore->transition($paths, $state, 'verifying');
            $this->healthChecker->assertHealthy($applicationRoot);
            $this->schedulerReadiness->assertReady($paths, $this->clock->now());

            $this->maintenanceStore->transition($paths, $state, 'completed');
            $this->maintenanceStore->clear($paths, $operationId);
            $this->inFlightTracker->stopDraining($paths);

            return new ReleaseOperationResult($operationId, $verification->releaseVersion, $verification->schemaMaximum, $backup->publicId);
        } catch (ReleaseOperationFailure $failure) {
            $this->markFailed($paths, $operationId, $failure->failureCode);
            throw $failure;
        } catch (Throwable $failure) {
            $this->markFailed($paths, $operationId, 'upgrade_failed');
            throw new ReleaseOperationFailure('upgrade_failed', 'The upgrade did not complete safely. The installation remains unavailable under the release hold; use the documented rollback or repair procedure.', $failure);
        } finally {
            if ($staging !== null) {
                $this->packagePublisher->discard($staging);
            }
            $lock->release();
        }
    }

    public function rollback(string $applicationRoot, string $archivePath, string $previousReleaseArchive, ?string $expectedSha256, int $drainTimeoutSeconds = 300): ReleaseOperationResult
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        if ($expectedSha256 === null || $expectedSha256 === '') {
            throw new ReleaseOperationFailure('package_digest_required', 'Rollback requires the external SHA-256 digest of the exact previously verified release package.');
        }
        $previousPackage = $this->verifyPackage($previousReleaseArchive, $expectedSha256);
        $checkpointSchema = $this->archiveStore->verify($paths, $archivePath);
        $this->assertPreUpgradeArchive($paths, $archivePath);
        $this->assertSchemaCompatible($checkpointSchema, $previousPackage->schemaMinimum, $previousPackage->schemaMaximum);
        $lock = $this->operationLock->acquire($paths);
        $existing = $this->maintenanceStore->current($paths);
        $operationId = $existing instanceof \Formvex\Spoke\Domain\Release\UpgradeMaintenanceState ? $existing->operationId : $this->identifierGenerator->uuidV7($this->clock->now());
        $staging = null;

        try {
            $now = $this->clock->now();
            $deadline = $now->add(new DateInterval('PT' . max(0, min(3600, $drainTimeoutSeconds)) . 'S'));
            $this->inFlightTracker->startDraining($paths);
            $state = $existing ?? $this->maintenanceStore->begin(
                $paths,
                $operationId,
                $this->currentRelease(),
                $previousPackage->releaseVersion,
                $this->archiveStore->currentSchemaVersion($paths),
                $checkpointSchema,
                $now,
                $deadline,
            );
            $this->waitForDrain($paths, $deadline);
            $state = $this->maintenanceStore->transition($paths, $state, 'rollback_restoring');
            $this->checkpointRestorer->restore($applicationRoot, $archivePath);
            $state = $this->maintenanceStore->transition($paths, $state, 'rollback_staging');
            $staging = $this->packagePublisher->stage($previousReleaseArchive, $operationId, $paths->runtime);
            $this->maintenanceStore->transition($paths, $state, 'rollback_publishing');
            $this->packagePublisher->publish($staging, $this->projectRoot);
            $this->healthChecker->assertHealthy($applicationRoot);
            $this->schedulerReadiness->assertReady($paths, $this->clock->now());
            $this->maintenanceStore->clear($paths, $operationId);
            $this->inFlightTracker->stopDraining($paths);

            return new ReleaseOperationResult($operationId, $previousPackage->releaseVersion, $checkpointSchema, basename($archivePath, '.zip'));
        } catch (ReleaseOperationFailure $failure) {
            $this->markFailed($paths, $operationId, $failure->failureCode);
            throw $failure;
        } catch (Throwable $failure) {
            $this->markFailed($paths, $operationId, 'rollback_failed');
            throw new ReleaseOperationFailure('rollback_failed', 'Rollback did not complete safely. The installation remains unavailable under the release hold.', $failure);
        } finally {
            if ($staging !== null) {
                $this->packagePublisher->discard($staging);
            }
            $lock->release();
        }
    }

    private function verifyPackage(string $archivePath, ?string $expectedSha256): \Formvex\Core\Release\ReleaseVerificationResult
    {
        try {
            return $this->packageVerifier->verify($archivePath, $expectedSha256);
        } catch (ReleaseVerificationFailure $failure) {
            throw new ReleaseOperationFailure($failure->failureCode, 'The release package failed verification. No installation state was changed.', $failure);
        }
    }

    private function waitForDrain(PrivateStoragePaths $paths, DateTimeImmutable $deadline): void
    {
        while ($this->inFlightTracker->activeCount($paths) > 0) {
            if ($this->clock->now() >= $deadline) {
                throw new ReleaseOperationFailure('drain_timeout', 'The upgrade could not finish draining admitted requests and workers before its deadline. The installation remains unavailable and no migration was started.');
            }
            usleep(100000);
        }
    }

    private function markFailed(PrivateStoragePaths $paths, string $operationId, string $failureCode): void
    {
        try {
            $state = $this->maintenanceStore->current($paths);
            if ($state !== null && $state->operationId === $operationId && $state->state !== 'failed') {
                $this->maintenanceStore->transition($paths, $state, 'failed_' . $failureCode);
            }
        } catch (Throwable) {
            // The existing maintenance marker is safer than clearing it after a failure.
        }
    }

    private function assertSchemaCompatible(string $schema, string $minimum, string $maximum): void
    {
        if (!preg_match('/\A\d{6}\z/', $schema) || !preg_match('/\A\d{6}\z/', $minimum) || !preg_match('/\A\d{6}\z/', $maximum) || (int) $schema < (int) $minimum || (int) $schema > (int) $maximum) {
            throw new ReleaseOperationFailure('schema_incompatible', 'The release and installation schema versions are not compatible. No installation state was changed.');
        }
    }

    private function assertPreUpgradeArchive(PrivateStoragePaths $paths, string $archivePath): void
    {
        $archive = realpath($archivePath);
        $directory = realpath($paths->preUpgradeBackups);
        if ($archive === false || $directory === false || !str_starts_with($archive, rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new ReleaseOperationFailure('checkpoint_not_paired', 'Rollback requires a verified pre-upgrade archive from this private installation.');
        }
    }

    private function currentRelease(): string
    {
        $manifestPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'RELEASE-MANIFEST.json';
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            return 'unversioned';
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        return is_array($manifest) && is_string($manifest['release_version'] ?? null) ? $manifest['release_version'] : 'unversioned';
    }
}
