<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormConfiguration\PageIdentity;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormConfigurationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use PDO;
use PHPUnit\Framework\TestCase;

final class FormConfigurationServiceTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    private FixedClock $clock;

    private FormConfigurationService $service;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-forms-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
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
        $this->clock = new FixedClock();
        $installationStore = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $this->clock,
            ),
            $this->clock,
            new FixedIdentifierGenerator(),
        );
        $installationStore->initialize($this->paths);
        $settingsStore = new PdoInstallationSettingsStore();
        $settingsStore->save(
            $this->paths,
            InstallationSettings::defaults()->withIdentity('Logoslab', 'logoslab.xyz', 'www.logoslab.xyz', 'admin@logoslab.xyz'),
            $this->clock->now(),
        );
        $this->service = new FormConfigurationService(
            new FormConfigurationStorageResolver($this->paths),
            new PdoFormConfigurationStore(),
            $settingsStore,
            new FixedIdentifierGenerator(),
            $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testDraftsRejectStaleWritesAndPublishedVersionsRemainImmutable(): void
    {
        $first = $this->service->create($this->temporaryRoot, $this->data('Initial subject'));
        self::assertSame(1, $first->draft->revision);
        self::assertCount(1, $first->draft->fields);

        $updated = $this->service->update($this->temporaryRoot, $first->draft->publicId, 1, $this->data('Updated subject'));
        self::assertSame(2, $updated->draft->revision);

        try {
            $this->service->update($this->temporaryRoot, $first->draft->publicId, 1, $this->data('Stale subject'));
            self::fail('A stale draft revision must be rejected.');
        } catch (FormConfigurationFailure $failure) {
            self::assertSame('draft_conflict', $failure->failureCode);
        }

        $published = $this->service->publish($this->temporaryRoot, $first->draft->publicId, 2);
        self::assertSame(1, $published->versionNumber);
        self::assertSame('Updated subject', $published->subject);

        $changed = $this->service->update($this->temporaryRoot, $first->draft->publicId, 2, $this->data('Second subject'));
        $second = $this->service->publish($this->temporaryRoot, $first->draft->publicId, $changed->draft->revision);
        $details = $this->service->details($this->temporaryRoot, $first->draft->publicId);

        self::assertSame(2, $second->versionNumber);
        self::assertSame(['Second subject', 'Updated subject'], array_map(static fn ($version): string => $version->subject, $details->publishedVersions));
    }

    public function testPublicResolutionReturnsOnlyAnActiveApprovedVersionAndTrashIsRecoverable(): void
    {
        $details = $this->service->create($this->temporaryRoot, $this->data('Public subject'));
        $published = $this->service->publish($this->temporaryRoot, $details->draft->publicId, 1);
        $connection = new PDO('sqlite:' . $this->paths->databaseFile());
        $connection->exec("UPDATE form_configuration_versions SET state = 'active' WHERE id = " . $this->versionId($connection, $details->draft->publicId, $published->versionNumber));

        $resolution = $this->service->resolvePublic($this->temporaryRoot, 'LOGOSLAB.XYZ.', '/contact?source=home', 'contact-form');
        self::assertNotNull($resolution);
        self::assertSame($details->draft->publicId, $resolution->publicFormId);
        self::assertSame(1, $resolution->configurationVersion);
        self::assertSame('contact-form', $resolution->formMarker);

        $this->service->trash($this->temporaryRoot, $details->draft->publicId);
        self::assertSame([], $this->service->list($this->temporaryRoot));
        self::assertCount(1, $this->service->list($this->temporaryRoot, true));

        $this->service->restore($this->temporaryRoot, $details->draft->publicId);
        self::assertCount(1, $this->service->list($this->temporaryRoot));
        $this->service->trash($this->temporaryRoot, $details->draft->publicId);
        $this->service->hardDelete($this->temporaryRoot, $details->draft->publicId);
        self::assertSame([], $this->service->list($this->temporaryRoot, true));
    }

    private function data(string $subject): FormConfigurationDraftData
    {
        return new FormConfigurationDraftData(
            'Contact form',
            PageIdentity::fromInput('logoslab.xyz', '/contact?source=home', 'contact-form'),
            'owner@logoslab.xyz',
            $subject,
            [new FormFieldDefinition('message', 'message', 'textarea', 'Message', 'message', 0, true, 10000)],
        );
    }

    private function versionId(PDO $connection, string $publicId, int $versionNumber): int
    {
        $statement = $connection->prepare('SELECT v.id FROM form_configuration_versions v INNER JOIN form_configurations f ON f.id = v.form_id WHERE f.public_id = :public_id AND v.version_number = :version_number');
        $statement->execute(['public_id' => $publicId, 'version_number' => $versionNumber]);

        return (int) $statement->fetchColumn();
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

final class FormConfigurationStorageResolver implements SpokeStorageResolver
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
