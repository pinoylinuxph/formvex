<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Security;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PublicPathStructureTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicRootProvider(): iterable
    {
        yield 'spoke public root' => ['apps/spoke/public'];
        yield 'hub public root' => ['apps/hub/public'];
    }

    #[DataProvider('publicRootProvider')]
    public function test_public_roots_reject_private_runtime_file_classes(string $relativeRoot): void
    {
        $root = dirname(__DIR__, 3) . '/' . $relativeRoot;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $prohibitedExtensions = [
            'db',
            'env',
            'key',
            'log',
            'pem',
            'sqlite',
            'sqlite3',
            'zip',
        ];
        $prohibitedNames = [
            'backup',
            'diagnostic',
            'export',
            'secret',
        ];

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            self::assertNotContains(
                strtolower($file->getExtension()),
                $prohibitedExtensions,
                $file->getPathname() . ' is a private file class under a public root.',
            );

            $lowerName = strtolower($file->getFilename());
            foreach ($prohibitedNames as $prohibitedName) {
                self::assertStringNotContainsString(
                    $prohibitedName,
                    $lowerName,
                    $file->getPathname() . ' has a private-data filename under a public root.',
                );
            }
        }
    }
}
