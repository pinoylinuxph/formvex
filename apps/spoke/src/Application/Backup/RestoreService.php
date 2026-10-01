<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Backup;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Backup\Contract\BackupOperationLock;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Throwable;

final readonly class RestoreService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private BackupArchiveStore $archiveStore,
        private BackupOperationLock $operationLock,
        private RecoveryHoldStore $recoveryHoldStore,
        private Clock $clock,
    ) {
    }

    public function validate(string $applicationRoot, string $archivePath): string
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $this->storageResolver->assertOperatorOwns($paths);

        return $this->archiveStore->verify($paths, $archivePath);
    }

    public function restore(string $applicationRoot, string $archivePath, bool $confirmed): string
    {
        if (!$confirmed) {
            throw new BackupFailure('restore_confirmation_required', 'Live restore was not started. Run the command again with --confirm after validating the archive.');
        }
        $paths = $this->storageResolver->resolve($applicationRoot);
        $this->storageResolver->assertOperatorOwns($paths);
        $this->archiveStore->verify($paths, $archivePath);
        $lock = $this->operationLock->acquire($paths, 'restore');
        $this->recoveryHoldStore->activate($paths, $this->clock->now(), 'restore', 'A server-side restore is validating and replacing local state.');
        try {
            $this->archiveStore->restore($paths, $archivePath);
            $this->recoveryHoldStore->clear($paths);

            return 'Restore completed. Existing administrator sessions were invalidated. The restored queue may contain a message delivered after the backup and can therefore send it again.';
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new BackupFailure('restore_failed', 'Restore failed after recovery hold was activated. The installation remains unavailable until a hosting administrator completes or repairs the restore.', $failure);
        } finally {
            $lock->release();
        }
    }
}
