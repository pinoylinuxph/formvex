<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Spoke\Application\Branding\BrandingService;
use Formvex\Spoke\Domain\Branding\BrandingUpload;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Infrastructure\Branding\LocalBrandingAssetStore;
use Formvex\Spoke\Infrastructure\Branding\SafeBrandingAssetValidator;
use Formvex\Spoke\Infrastructure\Persistence\PdoBrandingSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use PHPUnit\Framework\TestCase;

final class BrandingSettingsServiceTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-branding-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime', 'web'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }

        $this->paths = new PrivateStoragePaths(
            $this->temporaryRoot,
            $this->temporaryRoot . '/database',
            $this->temporaryRoot . '/secrets',
            $this->temporaryRoot . '/logs',
            $this->temporaryRoot . '/exports',
            $this->temporaryRoot . '/diagnostics',
            $this->temporaryRoot . '/backups/scheduled',
            $this->temporaryRoot . '/backups/manual',
            $this->temporaryRoot . '/backups/temporary',
            $this->temporaryRoot . '/runtime',
        );

        new PdoInstallationStore(
            new SqliteMigrationRunner(
                new \Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata(),
                new \Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth(),
                new \Formvex\Spoke\Migrations\Version000003CreateInstallationSettings(),
                new \Formvex\Spoke\Migrations\Version000004CreateFormConfiguration(),
                new \Formvex\Spoke\Migrations\Version000005CreateFormDiscovery(),
                new BrandingTestClock(),
                new \Formvex\Spoke\Migrations\Version000006CreateSubmissions(),
                new \Formvex\Spoke\Migrations\Version000007CreateSubmissionAbuse(),
                new \Formvex\Spoke\Migrations\Version000008CreateEmailDeliveryWorker(),
                new \Formvex\Spoke\Migrations\Version000009CreateFormActivation(),
                new \Formvex\Spoke\Migrations\Version000010CreateInstallationBranding(),
            ),
            new BrandingTestClock(),
            new BrandingTestIdentifierGenerator(),
        )->initialize($this->paths);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testSafeBrandingIsPersistedWithVersionedPublicAsset(): void
    {
        $service = $this->service();
        $result = $service->save(
            $this->temporaryRoot,
            ['brand_name' => 'Acme Portal', 'slogan' => 'Reliable forms for every team', 'show_slogan' => '1'],
            new BrandingUpload(__DIR__ . '/../../fixtures/branding/safe-logo.svg', 'customer-logo.svg', UPLOAD_ERR_OK),
            null,
            false,
            false,
        );

        self::assertSame('Acme Portal', $result->settings->brandName);
        self::assertTrue($result->settings->sloganVisible);
        self::assertNotNull($result->settings->logo);
        self::assertStringStartsWith('logo-2-', $result->settings->logo->filename);
        self::assertFileExists($this->temporaryRoot . '/web/branding/' . $result->settings->logo->filename);
        self::assertSame('Acme Portal', $service->snapshot($this->temporaryRoot)->brandName);
        self::assertSame('/branding/' . $result->settings->logo->filename, $service->viewModel($this->temporaryRoot)['logoUrl']);
    }

    public function testUnsafeSvgIsRejectedAndDefaultBrandingRemainsActive(): void
    {
        $service = $this->service();

        try {
            $service->save(
                $this->temporaryRoot,
                ['brand_name' => 'Unsafe', 'slogan' => '', 'show_slogan' => '0'],
                new BrandingUpload(__DIR__ . '/../../fixtures/branding/unsafe-logo.svg', 'logo.svg', UPLOAD_ERR_OK),
                null,
                false,
                false,
            );
            self::fail('Unsafe SVG content must be rejected.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('branding_logo_svg_invalid', $failure->failureCode);
        }

        self::assertSame('Noname', $service->snapshot($this->temporaryRoot)->brandName);
        self::assertFileDoesNotExist($this->temporaryRoot . '/web/branding');
    }

    public function testBrandingTextAndSwitchLimitsAreDescriptive(): void
    {
        $service = $this->service();

        try {
            $service->save($this->temporaryRoot, ['brand_name' => str_repeat('x', 81), 'slogan' => '', 'show_slogan' => '0'], null, null, false, false);
            self::fail('An overlong brand name must be rejected.');
        } catch (InstallationSettingsFailure $failure) {
            self::assertSame('branding_brand_name_invalid', $failure->failureCode);
            self::assertArrayHasKey('brand_name', $failure->fieldErrors);
        }
    }

    private function service(): BrandingService
    {
        return new BrandingService(
            new BrandingTestStorageResolver($this->paths),
            new PdoBrandingSettingsStore(),
            new SafeBrandingAssetValidator(),
            new LocalBrandingAssetStore($this->temporaryRoot . '/web'),
            new BrandingTestClock(),
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

final class BrandingTestClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T00:00:00+00:00');
    }
}

final class BrandingTestIdentifierGenerator implements IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        return '0199f2b8-7c3a-7f42-8c11-4ac3b865e092';
    }
}

final class BrandingTestStorageResolver implements \Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver
{
    public function __construct(private readonly PrivateStoragePaths $paths)
    {
    }

    public function resolve(string $applicationRoot): PrivateStoragePaths
    {
        return $this->paths;
    }

    public function assertOperatorOwns(PrivateStoragePaths $paths): void
    {
    }
}
