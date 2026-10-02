<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Core;

use Formvex\Core\Release\ReleasePackageVerifier;
use Formvex\Core\Release\ReleaseVerificationFailure;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ReleasePackageVerifierTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/formvex-release-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
    }

    public function testValidPackageAndManifestAreAccepted(): void
    {
        $archive = $this->createArchive([
            'apps/spoke/public/index.php' => '<?php',
            'build/client.js' => 'client',
            'build/spoke-admin.js' => 'admin',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php',
            'LICENSE' => 'MIT',
            'NOTICE' => 'Copyright (c) 2026 Royjieviv',
        ]);

        $result = new ReleasePackageVerifier()->verify($archive, hash_file('sha256', $archive) ?: null);

        self::assertSame('1.0.0', $result->releaseVersion);
        self::assertSame('000016', $result->schemaMinimum);
        self::assertCount(7, $result->entries);
    }

    public function testForbiddenApplicationAndPrivateEntriesAreRejected(): void
    {
        $archive = $this->createArchive(['apps/hub/src/Kernel.php' => '<?php']);

        try {
            new ReleasePackageVerifier()->verify($archive);
            self::fail('A package containing the Hub must be rejected.');
        } catch (ReleaseVerificationFailure $failure) {
            self::assertSame('private_entry_forbidden', $failure->failureCode);
        }
    }

    public function testTraversalEntryIsRejected(): void
    {
        $archive = $this->createArchive(['../private.txt' => 'private']);

        $this->expectException(ReleaseVerificationFailure::class);
        $this->expectExceptionMessage('unsafe entry name');
        new ReleasePackageVerifier()->verify($archive);
    }

    public function testManifestChecksumMismatchIsRejected(): void
    {
        $archive = $this->createArchive([
            'apps/spoke/public/index.php' => '<?php',
            'build/client.js' => 'changed',
            'build/spoke-admin.js' => 'admin',
            'composer.lock' => '{}',
            'vendor/autoload.php' => '<?php',
            'LICENSE' => 'MIT',
            'NOTICE' => 'notice',
        ], ['build/client.js' => str_repeat('0', 64)]);

        $this->expectException(ReleaseVerificationFailure::class);
        $this->expectExceptionMessage('checksum does not match');
        new ReleasePackageVerifier()->verify($archive);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, string> $overrides
     */
    private function createArchive(array $files, array $overrides = []): string
    {
        $manifestFiles = [];
        foreach ($files as $path => $contents) {
            $manifestFiles[] = [
                'path' => $path,
                'sha256' => $overrides[$path] ?? hash('sha256', $contents),
            ];
        }
        $manifest = json_encode([
            'schema_version' => 1,
            'release_version' => '1.0.0',
            'schema_compatibility' => ['minimum' => '000016', 'maximum' => '000016'],
            'files' => $manifestFiles,
        ], JSON_THROW_ON_ERROR);
        $path = $this->directory . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->addFromString('RELEASE-MANIFEST.json', $manifest));
        self::assertTrue($zip->close());

        return $path;
    }
}
