<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Console\RunDeliveryWorkerCommand;
use Formvex\Spoke\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeliveryWorkerCommandTest extends KernelTestCase
{
    private string $temporaryRoot;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-worker-command-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/web', 0o700, true);
        mkdir($this->temporaryRoot . '/formvex', 0o700, true);
        putenv('FORMVEX_APPLICATION_ROOT=' . $this->temporaryRoot . '/formvex');
        $_ENV['FORMVEX_APPLICATION_ROOT'] = $this->temporaryRoot . '/formvex';
        $_SERVER['FORMVEX_APPLICATION_ROOT'] = $this->temporaryRoot . '/formvex';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        putenv('FORMVEX_APPLICATION_ROOT');
        unset($_ENV['FORMVEX_APPLICATION_ROOT'], $_SERVER['FORMVEX_APPLICATION_ROOT']);
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testCronCommandReturnsSafeSummaryWithoutNodeRuntime(): void
    {
        $install = new CommandTester(self::getContainer()->get(InstallCommand::class));
        self::assertSame(0, $install->execute([
            '--application-root' => $this->temporaryRoot . '/formvex',
            '--web-root' => $this->temporaryRoot . '/web',
        ]));

        $worker = new CommandTester(self::getContainer()->get(RunDeliveryWorkerCommand::class));
        self::assertSame(0, $worker->execute(['--batch' => '1']));
        self::assertStringContainsString('OK delivery_worker:', $worker->getDisplay());
        self::assertStringNotContainsString($this->temporaryRoot, $worker->getDisplay());
        self::assertStringNotContainsString('smtp', strtolower($worker->getDisplay()));
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
