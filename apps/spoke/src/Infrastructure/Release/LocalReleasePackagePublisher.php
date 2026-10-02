<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Release;

use FilesystemIterator;
use Formvex\Spoke\Domain\Release\Contract\ReleasePackagePublisher;
use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

final class LocalReleasePackagePublisher implements ReleasePackagePublisher
{
    public function stage(string $archivePath, string $operationId, string $privateRuntime): string
    {
        if (!is_file($archivePath) || is_link($archivePath) || !preg_match('/\A[0-9a-f-]{16,80}\z/i', $operationId)) {
            throw new ReleaseOperationFailure('package_stage_invalid', 'The verified release package could not be staged safely.');
        }
        $staging = $privateRuntime . DIRECTORY_SEPARATOR . 'release-staging' . DIRECTORY_SEPARATOR . $operationId;
        if (is_dir($staging)) {
            throw new ReleaseOperationFailure('package_stage_exists', 'A release staging directory already exists for this operation.');
        }
        if (!mkdir($staging, 0o700, true) && !is_dir($staging)) {
            throw new ReleaseOperationFailure('package_stage_failed', 'The release staging directory could not be created.');
        }

        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            $this->discard($staging);
            throw new ReleaseOperationFailure('package_stage_failed', 'The verified release package could not be opened.');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $name === 'RELEASE-MANIFEST.json' || !$this->safeEntry($name)) {
                    if ($name === 'RELEASE-MANIFEST.json') {
                        $this->extractEntry($zip, $name, $staging);
                        continue;
                    }
                    throw new ReleaseOperationFailure('package_stage_invalid', 'The verified release package contains an unsafe entry.');
                }
                $this->extractEntry($zip, $name, $staging);
            }
        } catch (ReleaseOperationFailure $failure) {
            $zip->close();
            $this->discard($staging);
            throw $failure;
        } catch (Throwable $failure) {
            $zip->close();
            $this->discard($staging);
            throw new ReleaseOperationFailure('package_stage_failed', 'The release package could not be staged safely.', $failure);
        }
        $zip->close();

        return $staging;
    }

    public function publish(string $stagingPath, string $projectRoot): void
    {
        if (!is_dir($stagingPath) || is_link($stagingPath) || !is_dir($projectRoot) || is_link($projectRoot)) {
            throw new ReleaseOperationFailure('package_publish_failed', 'The staged release or application directory is unavailable.');
        }
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingPath, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                    throw new ReleaseOperationFailure('package_publish_failed', 'The staged release contains an unsupported file.');
                }
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($stagingPath, DIRECTORY_SEPARATOR)) + 1));
                if (!$this->safeEntry($relative)) {
                    throw new ReleaseOperationFailure('package_publish_failed', 'The staged release contains an unsafe file path.');
                }
                $target = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0o755, true) && !is_dir($parent)) {
                    throw new ReleaseOperationFailure('package_publish_failed', 'The new release directory could not be created.');
                }
                $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.publish';
                if (!copy($file->getPathname(), $temporary) || !chmod($temporary, 0o644) || !rename($temporary, $target)) {
                    @unlink($temporary);
                    throw new ReleaseOperationFailure('package_publish_failed', 'The new release file could not be published safely.');
                }
            }
        } catch (ReleaseOperationFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new ReleaseOperationFailure('package_publish_failed', 'The release could not be published safely.', $failure);
        }
    }

    public function discard(string $stagingPath): void
    {
        if (!is_dir($stagingPath) || is_link($stagingPath)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingPath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isLink() || $file->isFile()) {
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                rmdir($file->getPathname());
            }
        }
        rmdir($stagingPath);
    }

    private function extractEntry(ZipArchive $zip, string $name, string $staging): void
    {
        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new ReleaseOperationFailure('package_stage_failed', 'A release package file could not be read safely.');
        }
        $target = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0o700, true) && !is_dir($parent)) {
            fclose($stream);
            throw new ReleaseOperationFailure('package_stage_failed', 'A release package directory could not be created.');
        }
        $output = fopen($target, 'x');
        if ($output === false) {
            fclose($stream);
            throw new ReleaseOperationFailure('package_stage_failed', 'A release package file could not be staged.');
        }
        stream_copy_to_stream($stream, $output);
        fclose($stream);
        fclose($output);
        chmod($target, 0o600);
    }

    private function safeEntry(string $name): bool
    {
        return $name !== ''
            && !str_starts_with($name, '/')
            && !str_contains($name, "\0")
            && !str_contains($name, '\\')
            && !str_contains($name, '../')
            && !str_contains($name, '/./')
            && !str_ends_with($name, '/');
    }
}
