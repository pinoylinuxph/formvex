<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Diagnostics;

use Formvex\Spoke\Domain\Diagnostics\Contract\DiagnosticReportStore;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use JsonException;
use RuntimeException;

final class LocalDiagnosticReportStore implements DiagnosticReportStore
{
    public function write(PrivateStoragePaths $paths, DiagnosticReport $report, string $storageKey): int
    {
        $path = $this->path($paths, $storageKey);
        $temporary = $paths->diagnostics . DIRECTORY_SEPARATOR . '.diagnostic-report-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $contents = $report->toJson();
            if (strlen($contents) > DiagnosticReport::MAX_BYTES) {
                throw new DiagnosticReportFailure('report_too_large', 'The diagnostic report exceeded the safe size limit.');
            }
            $fileStat = is_file($path) ? stat($path) : false;
            if (is_link($path) || (is_array($fileStat) && $fileStat['nlink'] > 1)) {
                throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be stored safely.');
            }
            $handle = fopen($temporary, 'x');
            if ($handle === false || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be stored safely.');
            }
            fclose($handle);
            $handle = null;
            if (!chmod($temporary, 0o600) || !rename($temporary, $path)) {
                throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be stored safely.');
            }

            return strlen($contents);
        } catch (DiagnosticReportFailure $failure) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
            throw $failure;
        } catch (JsonException|RuntimeException $failure) {
            if (isset($handle) && is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
            throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be stored safely.', $failure);
        }
    }

    public function read(PrivateStoragePaths $paths, string $storageKey): DiagnosticReport
    {
        $path = $this->path($paths, $storageKey);
        if (!is_file($path) || is_link($path)) {
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available.');
        }
        $contents = file_get_contents($path);
        if ($contents === false || strlen($contents) > DiagnosticReport::MAX_BYTES) {
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available.');
        }
        try {
            $data = json_decode($contents, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available.', $failure);
        }
        if (!is_array($data)) {
            throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available.');
        }
        $normalized = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new DiagnosticReportFailure('report_unavailable', 'The diagnostic report is not available.');
            }
            $normalized[$key] = $value;
        }

        return DiagnosticReport::fromArray($normalized);
    }

    public function delete(PrivateStoragePaths $paths, string $storageKey): void
    {
        $path = $this->path($paths, $storageKey);
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be removed safely.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report could not be removed safely.');
        }
    }

    private function path(PrivateStoragePaths $paths, string $storageKey): string
    {
        if (preg_match('/\Adiagnostic-report-[0-9a-f-]{36}\.json\z/i', $storageKey) !== 1) {
            throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report storage key is invalid.');
        }
        $root = realpath($paths->diagnostics);
        if ($root === false || !is_dir($root) || is_link($paths->diagnostics)) {
            throw new DiagnosticReportFailure('report_storage_failed', 'The private diagnostic storage is unavailable.');
        }
        $path = $root . DIRECTORY_SEPARATOR . $storageKey;
        if (dirname($path) !== $root) {
            throw new DiagnosticReportFailure('report_storage_failed', 'The diagnostic report storage key is invalid.');
        }

        return $path;
    }
}
