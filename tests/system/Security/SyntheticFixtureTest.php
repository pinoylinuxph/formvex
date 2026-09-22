<?php

declare(strict_types=1);

namespace Formvex\Tests\System\Security;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SyntheticFixtureTest extends TestCase
{
    public function test_committed_fixtures_contain_no_secret_or_production_markers(): void
    {
        $fixtureRoot = dirname(__DIR__, 2) . '/fixtures';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
        );
        $prohibitedPatterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
            '/\\b(?:smtp_password|api_key|secret_key|access_token)\\s*[:=]\\s*["\'][^"\']+/i',
            '/@(?!example\\.(?:com|org|net)|invalid\\b)[a-z0-9.-]+\\.[a-z]{2,}/i',
        ];

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            foreach ($prohibitedPatterns as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    $file->getPathname() . ' contains a prohibited fixture marker.',
                );
            }
        }
    }
}
