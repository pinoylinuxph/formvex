<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportedHostFixtureTest extends TestCase
{
    #[DataProvider('hostFixtures')]
    public function testHostFixtureDeclaresTheReleasePreflightBoundary(string $fixture, bool $expectedPass): void
    {
        try {
            $profile = json_decode((string) file_get_contents($fixture), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            self::fail('The hosting fixture is not valid JSON: ' . $failure->getMessage());
        }
        self::assertIsArray($profile);
        self::assertIsString($profile['php'] ?? null);
        self::assertContains($profile['php'], ['8.4', '8.5']);
        self::assertSame(['ctype', 'curl', 'iconv', 'mbstring', 'openssl', 'pdo_sqlite', 'sodium', 'zip'], $profile['required_extensions'] ?? null);
        self::assertIsBool($profile['cli'] ?? null);
        self::assertIsBool($profile['https'] ?? null);
        self::assertIsBool($profile['private_root_outside_web_root'] ?? null);
        self::assertSame($expectedPass, $this->passesPreflightBoundary($profile));
    }

    /** @return array<string, array{string, bool}> */
    public static function hostFixtures(): array
    {
        $directory = dirname(__DIR__, 2) . '/fixtures/hosting';

        return [
            'php 8.4' => [$directory . '/php-8.4.json', true],
            'php 8.5' => [$directory . '/php-8.5.json', true],
            'missing zip' => [$directory . '/missing-zip.json', false],
            'private root exposed' => [$directory . '/private-root-exposed.json', false],
        ];
    }

    /** @param array<string, mixed> $profile */
    private function passesPreflightBoundary(array $profile): bool
    {
        return $profile['cli'] === true
            && $profile['https'] === true
            && $profile['private_root_outside_web_root'] === true
            && $profile['writable_private_root'] === true
            && count($profile['required_extensions']) === 8
            && !isset($profile['missing']);
    }
}
