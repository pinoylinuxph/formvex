<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\Filesystem;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Security\LocalSmtpSecretStore;
use PHPUnit\Framework\TestCase;

final class LocalSmtpSecretStoreTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-smtp-secret-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/secrets', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testSecretIsWrittenWithOwnerOnlyPermissionsAndCanBeRotated(): void
    {
        $paths = $this->paths();
        $store = new LocalSmtpSecretStore();

        $store->write($paths, 'a', 'first-password');
        self::assertSame('first-password', $store->read($paths, 'a'));
        self::assertTrue($store->isConfigured($paths, 'a'));
        self::assertSame(0, fileperms($paths->secrets . '/smtp-password-a.php') & 0o077);

        $store->write($paths, 'b', 'replacement-password');
        self::assertSame('replacement-password', $store->read($paths, 'b'));
        self::assertNotSame($store->read($paths, 'a'), $store->read($paths, 'b'));

        $store->remove($paths, 'a');
        self::assertFalse($store->isConfigured($paths, 'a'));
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot,
            $this->temporaryRoot,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
