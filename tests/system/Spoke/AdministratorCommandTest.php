<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Spoke;

use Formvex\Spoke\Console\BootstrapAdministratorCommand;
use Formvex\Spoke\Console\InstallCommand;
use Formvex\Spoke\Console\ResetAdministratorPasswordCommand;
use Formvex\Spoke\Kernel;
use PDO;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AdministratorCommandTest extends KernelTestCase
{
    private string $temporaryRoot;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-admin-command-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot . '/web', 0o700, true);
        mkdir($this->temporaryRoot . '/formvex', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
        self::ensureKernelShutdown();
    }

    public function testBootstrapIsOneTimeAndDoesNotPrintPrivatePaths(): void
    {
        $this->install();
        $command = self::getContainer()->get(BootstrapAdministratorCommand::class);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--application-root' => $this->temporaryRoot . '/formvex']));
        $output = $tester->getDisplay();
        self::assertStringContainsString('SUCCESS administrator: bootstrapped', $output);
        self::assertStringNotContainsString($this->temporaryRoot, $output);
        $matchCount = preg_match('/TEMPORARY_PASSWORD: ([A-Za-z0-9_-]+)/', $output, $matches);
        self::assertSame(1, $matchCount);

        $password = $matches[1] ?? '';
        $connection = new PDO('sqlite:' . $this->temporaryRoot . '/formvex/database/formvex.sqlite');
        $storedHash = (string) $connection->query('SELECT password_hash FROM local_administrators WHERE singleton_id = 1')->fetchColumn();
        self::assertStringNotContainsString($password, $storedHash);

        $secondTester = new CommandTester($command);
        self::assertSame(1, $secondTester->execute(['--application-root' => $this->temporaryRoot . '/formvex']));
        self::assertStringContainsString('administrator_already_exists', $secondTester->getDisplay());
        self::assertStringNotContainsString($password, $secondTester->getDisplay());
    }

    public function testResetPrintsAReplacementPassword(): void
    {
        $this->install();
        $bootstrap = new CommandTester(self::getContainer()->get(BootstrapAdministratorCommand::class));
        self::assertSame(0, $bootstrap->execute(['--application-root' => $this->temporaryRoot . '/formvex']));
        preg_match('/TEMPORARY_PASSWORD: ([A-Za-z0-9_-]+)/', $bootstrap->getDisplay(), $initialMatches);

        $reset = new CommandTester(self::getContainer()->get(ResetAdministratorPasswordCommand::class));
        self::assertSame(0, $reset->execute(['--application-root' => $this->temporaryRoot . '/formvex']));
        preg_match('/TEMPORARY_PASSWORD: ([A-Za-z0-9_-]+)/', $reset->getDisplay(), $resetMatches);

        self::assertNotSame($initialMatches[1] ?? '', $resetMatches[1] ?? '');
        self::assertStringNotContainsString($this->temporaryRoot, $reset->getDisplay());
    }

    private function install(): void
    {
        $command = self::getContainer()->get(InstallCommand::class);
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([
            '--application-root' => $this->temporaryRoot . '/formvex',
            '--web-root' => $this->temporaryRoot . '/web',
        ]));
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
