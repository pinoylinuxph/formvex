<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Repository;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DependencyDirectionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function prohibitedImportProvider(): iterable
    {
        yield 'core cannot import applications' => [
            'packages/core/src',
            '/\\bFormvex\\\\(?:Spoke|Hub)\\\\/',
        ];
        yield 'contracts cannot import applications' => [
            'packages/contracts/src',
            '/\\bFormvex\\\\(?:Spoke|Hub)\\\\/',
        ];
        yield 'spoke cannot import hub' => [
            'apps/spoke/src',
            '/\\bFormvex\\\\Hub\\\\/',
        ];
        yield 'hub cannot import spoke' => [
            'apps/hub/src',
            '/\\bFormvex\\\\Spoke\\\\/',
        ];
    }

    #[DataProvider('prohibitedImportProvider')]
    public function test_it_rejects_prohibited_cross_boundary_imports(
        string $directory,
        string $prohibitedPattern,
    ): void {
        foreach ($this->phpFiles($directory) as $file) {
            $source = file_get_contents($file->getPathname());

            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                $prohibitedPattern,
                $source,
                $file->getPathname() . ' crosses an approved dependency boundary.',
            );
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                dirname(__DIR__, 3) . '/' . $directory,
                FilesystemIterator::SKIP_DOTS,
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && 'php' === $file->getExtension()) {
                yield $file;
            }
        }
    }
}
