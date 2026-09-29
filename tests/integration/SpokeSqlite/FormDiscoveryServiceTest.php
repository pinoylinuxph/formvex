<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\FormDiscovery\FormDiscoveryService;
use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\FormFieldDefinition;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormConfigurationStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormDiscoveryStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Persistence\PdoInstallationStore;
use Formvex\Spoke\Infrastructure\Persistence\SqliteMigrationRunner;
use Formvex\Spoke\Migrations\Version000001CreateInstallationMetadata;
use Formvex\Spoke\Migrations\Version000002CreateLocalAdministratorAuth;
use Formvex\Spoke\Migrations\Version000003CreateInstallationSettings;
use Formvex\Spoke\Migrations\Version000004CreateFormConfiguration;
use Formvex\Spoke\Migrations\Version000005CreateFormDiscovery;
use PHPUnit\Framework\TestCase;

final class FormDiscoveryServiceTest extends TestCase
{
    private string $temporaryRoot;

    private FormDiscoveryService $service;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-discovery-' . bin2hex(random_bytes(8));

        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }

        $paths = new PrivateStoragePaths(
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
        $clock = new FixedClock();
        $installationStore = new PdoInstallationStore(
            new SqliteMigrationRunner(
                new Version000001CreateInstallationMetadata(),
                new Version000002CreateLocalAdministratorAuth(),
                new Version000003CreateInstallationSettings(),
                new Version000004CreateFormConfiguration(),
                new Version000005CreateFormDiscovery(),
                $clock,
            ),
            $clock,
            new FixedIdentifierGenerator(),
        );
        $installationStore->initialize($paths);
        $settingsStore = new PdoInstallationSettingsStore();
        $settingsStore->save($paths, InstallationSettings::defaults()->withIdentity('Logoslab', 'logoslab.xyz', 'www.logoslab.xyz', 'admin@logoslab.xyz'), $clock->now());
        $storageResolver = new DiscoveryStorageResolver($paths);
        $formConfigurationService = new FormConfigurationService(
            $storageResolver,
            new PdoFormConfigurationStore(),
            $settingsStore,
            new FixedIdentifierGenerator(),
            $clock,
        );
        $this->service = new FormDiscoveryService(
            $storageResolver,
            new PdoFormDiscoveryStore(),
            $formConfigurationService,
            $settingsStore,
            new DiscoverySecurityTokenGenerator(),
            new FixedIdentifierGenerator(),
            $clock,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testDiscoveryIsBoundToConfiguredPageAndCanBeAppliedOnlyAfterMappingReview(): void
    {
        $authorization = $this->service->begin($this->temporaryRoot, 'administrator-session', 'LOGOSLAB.XYZ.', '/contact?source=home');
        self::assertStringStartsWith('https://logoslab.xyz/contact#formvex_discovery=', $authorization->discoveryUrl);

        $candidate = $this->service->redeem($this->temporaryRoot, $authorization->token, 'logoslab.xyz', $this->payload(), 2048);
        self::assertCount(1, $this->service->candidates($this->temporaryRoot, 'administrator-session'));

        try {
            $this->service->applyCandidate($this->temporaryRoot, 'administrator-session', $candidate->candidateId, null, 0, 1, [
                new FormFieldDefinition('email', 'email', 'email', 'Email', 'unmapped', 0, true, 255),
            ]);
            self::fail('An unresolved mapping must not be applied.');
        } catch (FormDiscoveryFailure $failure) {
            self::assertSame('discovery_mapping_incomplete', $failure->failureCode);
        }

        $details = $this->service->applyCandidate($this->temporaryRoot, 'administrator-session', $candidate->candidateId, null, 0, 1, [
            new FormFieldDefinition('email', 'email', 'email', 'Email', 'email', 0, true, 255),
        ]);
        self::assertSame('contact-form', $details->draft->page->formMarker);
        self::assertSame('email', $details->draft->fields[0]->parameterKey);

        try {
            $this->service->redeem($this->temporaryRoot, $authorization->token, 'logoslab.xyz', $this->payload(), 2048);
            self::fail('A redeemed capability must not be replayable.');
        } catch (FormDiscoveryFailure $failure) {
            self::assertSame('discovery_replayed', $failure->failureCode);
        }
    }

    public function testDiscoveryRejectsWrongSessionAndUnconfiguredHost(): void
    {
        $authorization = $this->service->begin($this->temporaryRoot, 'administrator-session', 'logoslab.xyz', '/contact');

        try {
            $this->service->candidates($this->temporaryRoot, 'other-session');
            self::assertSame([], $this->service->candidates($this->temporaryRoot, 'other-session'));
        } catch (FormDiscoveryFailure $failure) {
            self::fail($failure->getMessage());
        }

        try {
            $this->service->redeem($this->temporaryRoot, $authorization->token, 'untrusted.example', $this->payload(), 2048);
            self::fail('An unconfigured host must be rejected.');
        } catch (FormDiscoveryFailure $failure) {
            self::assertContains($failure->failureCode, ['page_host_not_allowed', 'discovery_not_authorized']);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'page_path' => '/contact',
            'forms' => [[
                'form_marker' => 'contact-form',
                'display_name' => 'Contact form',
                'marker_generated' => false,
                'ambiguous' => false,
                'controls' => [[
                    'discovery_key' => 'control-1-1',
                    'control_name' => 'email',
                    'control_type' => 'email',
                    'display_label' => 'Email',
                    'label_resolved' => true,
                    'required' => true,
                    'max_length' => 255,
                    'choices' => [],
                    'choice_group_key' => null,
                    'suggested_parameters' => ['email'],
                ]],
                'unsupported_controls' => [],
            ]],
        ];
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

final class DiscoveryStorageResolver implements SpokeStorageResolver
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

final class DiscoverySecurityTokenGenerator implements SecurityTokenGenerator
{
    public function generate(int $bytes = 32): string
    {
        return 'discovery-capability-token';
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
