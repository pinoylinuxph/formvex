<?php

declare(strict_types=1);

namespace Formvex\Tools\Release;

use Formvex\Core\Release\ReleasePackageVerifier;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;

final class ReleasePackageBuilder
{
    /**
     * @var list<string>
     */
    private const DIRECTORIES = ['apps/spoke', 'packages', 'build', 'docs/release'];

    /**
     * @var list<string>
     */
    private const FILES = ['composer.json', 'composer.lock', 'README.md', 'LICENSE', 'NOTICE'];

    public function __construct(
        private readonly string $sourceRoot,
        private readonly string $outputZip,
        private readonly string $releaseVersion,
    ) {
    }

    public function build(): string
    {
        $this->assertSource();
        $outputDirectory = dirname($this->outputZip);
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0o700, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException('The release output directory could not be created.');
        }

        $staging = $outputDirectory . DIRECTORY_SEPARATOR . '.release-staging-' . bin2hex(random_bytes(8));
        mkdir($staging, 0o700, true);
        try {
            foreach (self::DIRECTORIES as $directory) {
                $this->copyTree($this->sourceRoot . DIRECTORY_SEPARATOR . $directory, $staging . DIRECTORY_SEPARATOR . $directory);
            }
            foreach (self::FILES as $file) {
                $this->copyFile($this->sourceRoot . DIRECTORY_SEPARATOR . $file, $staging . DIRECTORY_SEPARATOR . $file);
            }
            $this->installProductionDependencies($staging);
            $this->writeManifest($staging);
            $this->writeArchive($staging);

            $digest = hash_file('sha256', $this->outputZip);
            if ($digest === false || file_put_contents($this->outputZip . '.sha256', $digest . "  " . basename($this->outputZip) . "\n", LOCK_EX) === false) {
                throw new RuntimeException('The release digest could not be written.');
            }

            (new ReleasePackageVerifier())->verify($this->outputZip, $digest);

            return $digest;
        } finally {
            $this->removeTree($staging);
        }
    }

    private function assertSource(): void
    {
        if (!is_dir($this->sourceRoot) || !is_file($this->sourceRoot . '/composer.json') || !is_file($this->sourceRoot . '/composer.lock')) {
            throw new RuntimeException('The release source must be a repository with locked Composer dependencies.');
        }
        if (!preg_match('/\A[vV]?\d+\.\d+\.\d+\z/', $this->releaseVersion)) {
            throw new RuntimeException('The release version must use a numeric semantic version.');
        }
    }

    private function copyTree(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('A required release source directory is missing.');
        }
        if (!mkdir($destination, 0o700, true) && !is_dir($destination)) {
            throw new RuntimeException('A release staging directory could not be created.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isLink() || str_contains('/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/var/') || str_contains('/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative), '/branding/') && str_starts_with(str_replace(DIRECTORY_SEPARATOR, '/', $relative), 'public/branding/')) {
                if ($item->isLink()) {
                    throw new RuntimeException('Symlinks are not allowed in the release source.');
                }
                continue;
            }
            if ($item->isDir()) {
                mkdir($target, 0o700, true);
                continue;
            }
            if (str_ends_with($relative, '.map') || str_starts_with(basename($relative), '.env')) {
                continue;
            }
            $this->copyFile($item->getPathname(), $target);
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('A required release source file is missing or is a symlink.');
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0o700, true) && !is_dir($parent)) {
            throw new RuntimeException('A release staging directory could not be created.');
        }
        if (!copy($source, $destination)) {
            throw new RuntimeException('A release source file could not be staged.');
        }
        chmod($destination, 0o600);
    }

    private function installProductionDependencies(string $staging): void
    {
        $command = 'composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-scripts --working-dir=' . escapeshellarg($staging);
        exec($command . ' 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Production Composer dependencies could not be installed: ' . implode(' ', array_slice($output, -8)));
        }
    }

    private function writeManifest(string $staging): void
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($staging) + 1));
            if ($path === 'RELEASE-MANIFEST.json') {
                continue;
            }
            $hash = hash_file('sha256', $item->getPathname());
            if ($hash === false) {
                throw new RuntimeException('A release file checksum could not be calculated.');
            }
            $files[] = ['path' => $path, 'sha256' => $hash, 'size_bytes' => $item->getSize()];
        }
        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);
        $manifest = [
            'schema_version' => 1,
            'application' => 'formvex-spoke',
            'release_version' => $this->releaseVersion,
            'archive_kind' => 'production_zip',
            'schema_compatibility' => ['minimum' => '000016', 'maximum' => '000016'],
            'supported_php' => ['8.4', '8.5'],
            'supported_browsers' => ['evergreen'],
            'required_extensions' => ['ctype', 'curl', 'iconv', 'mbstring', 'openssl', 'pdo_sqlite', 'sodium', 'zip'],
            'build_metadata' => ['composer' => $this->toolVersion('composer'), 'node' => $this->toolVersion('node'), 'npm' => $this->toolVersion('npm')],
            'manifest_excludes_self' => true,
            'files' => $files,
        ];
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($staging . '/RELEASE-MANIFEST.json', $encoded, LOCK_EX) === false) {
            throw new RuntimeException('The release manifest could not be written.');
        }
        chmod($staging . '/RELEASE-MANIFEST.json', 0o600);
    }

    private function writeArchive(string $staging): void
    {
        @unlink($this->outputZip);
        $zip = new ZipArchive();
        if ($zip->open($this->outputZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The release ZIP could not be created.');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($item->getPathname(), strlen($staging) + 1));
                $zip->addFile($item->getPathname(), $path);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('The release ZIP could not be finalized.');
        }
        chmod($this->outputZip, 0o600);
    }

    private function toolVersion(string $tool): string
    {
        exec($tool . ' --version 2>/dev/null', $output, $status);

        return $status === 0 && isset($output[0]) ? trim($output[0]) : 'unavailable';
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
