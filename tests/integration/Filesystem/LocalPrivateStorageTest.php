<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\Filesystem;

use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\PreflightCheck;
use Formvex\Spoke\Domain\Installation\PreflightCheckStatus;
use Formvex\Spoke\Infrastructure\Filesystem\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class LocalPrivateStorageTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-storage-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/web/formvex', 0o700, true);
        mkdir($this->temporaryRoot . '/private', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testOutsideWebRootPassesWithoutExternalProbe(): void
    {
        $verifier = new RecordingWebExposureVerifier(true);
        $storage = new LocalPrivateStorage($verifier);
        $configuration = InstallationConfiguration::fromOperatorInput(
            $this->temporaryRoot . '/private',
            $this->temporaryRoot . '/web',
            null,
        );

        $checks = $storage->check($configuration);

        self::assertContains('private_web_protection', array_map(
            static fn ($check): string => $check->code,
            $checks,
        ));
        self::assertSame([], $verifier->urls);
    }

    public function testWebRootStorageRequiresAndVerifiesDeniedSentinel(): void
    {
        $verifier = new RecordingWebExposureVerifier(true);
        $storage = new LocalPrivateStorage($verifier);
        $configuration = InstallationConfiguration::fromOperatorInput(
            $this->temporaryRoot . '/web/formvex',
            $this->temporaryRoot . '/web',
            'https://example.test',
        );

        $checks = $storage->check($configuration);

        self::assertSame(PreflightCheckStatus::Pass, $this->findCheck($checks, 'private_web_protection')->status);
        self::assertCount(1, $verifier->urls);
        self::assertStringStartsWith('https://example.test/formvex/.formvex-preflight-', $verifier->urls[0]);
    }

    public function testWebRootStorageFailsWhenSentinelIsExposed(): void
    {
        $storage = new LocalPrivateStorage(new RecordingWebExposureVerifier(false));
        $configuration = InstallationConfiguration::fromOperatorInput(
            $this->temporaryRoot . '/web/formvex',
            $this->temporaryRoot . '/web',
            'https://example.test',
        );

        $checks = $storage->check($configuration);

        self::assertSame(PreflightCheckStatus::Fail, $this->findCheck($checks, 'private_web_protection_exposed')->status);
    }

    /** @param list<PreflightCheck> $checks */
    private function findCheck(array $checks, string $code): PreflightCheck
    {
        foreach ($checks as $check) {
            if ($check->code === $code) {
                return $check;
            }
        }

        self::fail(sprintf('Check %s was not returned.', $code));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
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
