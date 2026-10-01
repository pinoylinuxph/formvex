<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Storage;

use DateInterval;
use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Storage\Contract\StorageCapacityGuard;
use Formvex\Spoke\Domain\Storage\Contract\StorageExportRepository;
use Formvex\Spoke\Domain\Storage\Contract\SubmissionExportReader;
use Formvex\Spoke\Domain\Storage\StorageExport;
use Formvex\Spoke\Domain\Storage\StorageExportFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use Formvex\Spoke\Infrastructure\Filesystem\CsvExportWriter;
use Formvex\Spoke\Infrastructure\Filesystem\LocalStorageLock;
use Throwable;

final readonly class StorageExportService
{
    private const MAX_ROWS = 10000;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private StorageCapacityGuard $capacityGuard,
        private StorageExportRepository $exportRepository,
        private SubmissionExportReader $exportReader,
        private CsvExportWriter $writer,
        private IdentifierGenerator $identifierGenerator,
        private Clock $clock,
    ) {
    }

    public function generate(string $applicationRoot, SubmissionReviewQuery $query): StorageExport
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $lock = $this->acquireLock($paths->runtime . DIRECTORY_SEPARATOR . 'storage-export.lock');
        $now = $this->clock->now();
        try {
            if ($this->exportRepository->hasActive($paths, $now)) {
                throw new StorageExportFailure('export_in_progress', 'An export is already available for download. Download or wait for it to expire before creating another export.');
            }
            if (!$this->capacityGuard->evaluate($paths)->allowed) {
                throw new StorageExportFailure('storage_unavailable', 'The export could not be created because private storage is full or unavailable. Existing records were not changed.');
            }

            $data = $this->exportReader->read($paths, $query, self::MAX_ROWS);
            if ($data->total > self::MAX_ROWS) {
                throw new StorageExportFailure('export_limit_reached', 'The selected result contains more than 10,000 records. Add filters and try the export again.');
            }

            $publicId = $this->identifierGenerator->uuidV7($now);
            $fileSize = $this->writer->write($paths, $publicId, $data);
            if (!$this->capacityGuard->evaluate($paths, $fileSize + 4096)->allowed) {
                throw new StorageExportFailure('storage_unavailable', 'The export could not be created because private storage is full or temporarily unavailable. Existing records were not changed.');
            }
            $export = new StorageExport($publicId, $data->total, $fileSize, $now, $now->add(new DateInterval('PT15M')), null, 'available');
            $this->exportRepository->create($paths, $export, http_build_query($query->toQuery(), '', '&', PHP_QUERY_RFC3986));

            return $export;
        } catch (Throwable $failure) {
            if (isset($publicId)) {
                @unlink($paths->exports . DIRECTORY_SEPARATOR . $publicId . '.csv');
            }
            if ($failure instanceof StorageExportFailure) {
                throw $failure;
            }
            throw new StorageExportFailure('export_failed', 'The CSV export could not be created. Existing submissions were not changed.');
        } finally {
            $lock->release();
        }
    }

    public function download(string $applicationRoot, string $publicId): string
    {
        if (!preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $publicId)) {
            throw new StorageExportFailure('export_not_available', 'This export is no longer available. Create a new export from Submissions.');
        }

        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->clock->now();
        $export = $this->exportRepository->find($paths, $publicId);
        $file = $paths->exports . DIRECTORY_SEPARATOR . $publicId . '.csv';
        if ($export === null || !$export->isAvailable($now) || !is_file($file) || is_link($file)) {
            throw new StorageExportFailure('export_not_available', 'This export is no longer available. Create a new export from Submissions.');
        }

        try {
            $this->exportRepository->markDownloaded($paths, $publicId, $now);
        } catch (Throwable) {
            throw new StorageExportFailure('export_not_available', 'This export could not be authorized for download. Create a new export from Submissions.');
        }

        return $file;
    }

    private function acquireLock(string $path): LocalStorageLock
    {
        $handle = fopen($path, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new StorageExportFailure('export_in_progress', 'An export is already being created. Wait for it to finish and try again.');
        }
        chmod($path, 0o600);

        return new LocalStorageLock($handle, $path);
    }
}
