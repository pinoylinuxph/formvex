<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Domain\Release\ReleaseOperationFailure;
use Formvex\Spoke\Infrastructure\Release\LocalReleasePackagePublisher;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LocalReleasePackagePublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-publisher-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/runtime', 0o700, true);
        mkdir($this->root . '/code', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testStagesPublishesAndDiscardsVerifiedPackageFiles(): void
    {
        $archive = $this->archive(['apps/spoke/public/index.php' => '<?php echo "release";']);
        $publisher = new LocalReleasePackagePublisher();
        $staging = $publisher->stage($archive, '01a0f300-0000-7000-8000-000000000001', $this->root . '/runtime');

        self::assertFileExists($staging . '/apps/spoke/public/index.php');
        $publisher->publish($staging, $this->root . '/code');
        self::assertSame('<?php echo "release";', file_get_contents($this->root . '/code/apps/spoke/public/index.php'));

        $publisher->discard($staging);
        self::assertDirectoryDoesNotExist($staging);
    }

    public function testRejectsTraversalDuringStaging(): void
    {
        $archive = $this->archive(['../escaped.php' => '<?php']);
        $publisher = new LocalReleasePackagePublisher();

        $this->expectException(ReleaseOperationFailure::class);
        $this->expectExceptionMessage('unsafe entry');
        $publisher->stage($archive, '01a0f300-0000-7000-8000-000000000002', $this->root . '/runtime');
    }

    /** @param array<string, string> $files */
    private function archive(array $files): string
    {
        $path = $this->root . '/package.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            self::assertTrue($zip->addFromString($name, $contents));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}
