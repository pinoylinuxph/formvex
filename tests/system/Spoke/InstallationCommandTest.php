<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallationCommandTest extends KernelTestCase
{
    private string $temporaryRoot;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-command-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/web', 0o700, true);
        mkdir($this->temporaryRoot . '/formvex', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
        self::ensureKernelShutdown();
    }

    public function testInstallationCommandInitializesAndRerunsIdempotently(): void
    {
        $command = self::getContainer()->get(InstallCommand::class);
        $tester = new CommandTester($command);
        $input = [
            '--application-root' => $this->temporaryRoot . '/formvex',
            '--web-root' => $this->temporaryRoot . '/web',
        ];

        self::assertSame(0, $tester->execute($input));
        self::assertStringContainsString('SUCCESS installation: initialized', $tester->getDisplay());

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($input));
        self::assertStringContainsString('SUCCESS installation: already_initialized', $tester->getDisplay());
    }

    public function testInvalidApplicationRootReturnsSafeFailure(): void
    {
        $command = self::getContainer()->get(InstallCommand::class);
        $tester = new CommandTester($command);
        $input = [
            '--application-root' => $this->temporaryRoot . '/missing',
            '--web-root' => $this->temporaryRoot . '/web',
        ];

        self::assertSame(1, $tester->execute($input));
        self::assertStringContainsString('application_root_invalid', $tester->getDisplay());
        self::assertStringNotContainsString($this->temporaryRoot, $tester->getDisplay());
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
