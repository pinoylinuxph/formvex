<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use Formvex\Spoke\Application\FormChangeObservation\FormChangeObservationService;
use Formvex\Spoke\Domain\FormChangeObservation\ObservedFormStructure;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormChangeObservationRepository;
use Formvex\Spoke\Infrastructure\Persistence\PdoFormConfigurationStore;
use Formvex\Spoke\Migrations\Version000018CreateFormChangeObservations;
use PDO;
use PHPUnit\Framework\TestCase;

final class FormChangeObservationServiceTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-change-observation-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
        $this->now = new DateTimeImmutable('2026-10-04T10:00:00.000000Z');
        $this->createFormConfiguration();
        new Version000018CreateFormChangeObservations()->up($this->connection());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnchangedStructureIsQuietAndChangedStructureIsDeduplicated(): void
    {
        $identifier = $this->createMock(IdentifierGenerator::class);
        $identifier->method('uuidV7')->willReturnOnConsecutiveCalls(
            '0195f2b8-7c3a-7f42-8c11-4ac3b865e092',
            '0195f2b8-7c3a-7f42-8c11-4ac3b865e094',
            '0195f2b8-7c3a-7f42-8c11-4ac3b865e093',
        );
        $service = new FormChangeObservationService(
            new FormChangeObservationStorageResolver($this->paths),
            new PdoFormConfigurationStore(),
            new PdoFormChangeObservationRepository(),
            $identifier,
            new FixedClock(),
        );
        $same = new ObservedFormStructure(1, 1, '/contact', 'contactForm', hash('sha256', json_encode([[
            'control_name' => 'message',
            'control_type' => 'textarea',
            'required' => true,
            'max_length' => 10000,
            'choice_values' => [],
        ]], JSON_THROW_ON_ERROR)), [[
            'control_name' => 'message',
            'control_type' => 'textarea',
            'required' => true,
            'max_length' => 10000,
            'choice_values' => [],
        ]]);
        $changedControls = [[
            'control_name' => 'message',
            'control_type' => 'textarea',
            'required' => false,
            'max_length' => 10000,
            'choice_values' => [],
        ]];
        $changed = new ObservedFormStructure(1, 1, '/contact', 'contactForm', hash('sha256', json_encode($changedControls, JSON_THROW_ON_ERROR)), $changedControls);

        $service->observe($this->root, '01a0ec00-07c0-70e5-be5f-969fce035077', $same);
        self::assertSame(0, $service->countOpen($this->root));

        $service->observe($this->root, '01a0ec00-07c0-70e5-be5f-969fce035077', $changed);
        $service->observe($this->root, '01a0ec00-07c0-70e5-be5f-969fce035077', $changed);
        self::assertCount(1, $service->listOpen($this->root));
        self::assertSame('required_changed', $service->listOpen($this->root)[0]->differences[0]['kind']);

        $service->resolve($this->root, '0195f2b8-7c3a-7f42-8c11-4ac3b865e092', 'admin');
        self::assertSame(0, $service->countOpen($this->root));
        self::assertSame(
            'spoke.form_change_observation.resolved',
            $this->connection()->query('SELECT event_name FROM audit_events')->fetchColumn(),
        );
        self::assertSame(
            '{"actor":"admin"}',
            $this->connection()->query('SELECT metadata_json FROM audit_events')->fetchColumn(),
        );

        $service->observe($this->root, '01a0ec00-07c0-70e5-be5f-969fce035077', $changed);
        self::assertSame(1, $service->countOpen($this->root));
        $service->discard($this->root, '0195f2b8-7c3a-7f42-8c11-4ac3b865e093', 'admin');
        self::assertSame(0, $service->countOpen($this->root));
        self::assertSame(
            'spoke.form_change_observation.discarded',
            $this->connection()->query("SELECT event_name FROM audit_events ORDER BY id DESC LIMIT 1")->fetchColumn(),
        );
    }

    private function createFormConfiguration(): void
    {
        $connection = $this->connection();
        $connection->exec('CREATE TABLE form_configurations (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, deleted_at TEXT NULL)');
        $connection->exec("CREATE TABLE form_configuration_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, form_id INTEGER NOT NULL, version_number INTEGER NOT NULL, state TEXT NOT NULL, revision INTEGER NOT NULL, recipient TEXT NOT NULL, subject TEXT NOT NULL, captcha_enabled INTEGER NOT NULL DEFAULT 0, captcha_site_key TEXT NOT NULL DEFAULT '', evidence_revision INTEGER NOT NULL DEFAULT 1, published_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
        $connection->exec('CREATE TABLE form_configuration_version_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, version_id INTEGER NOT NULL, host TEXT NOT NULL, path TEXT NOT NULL, form_marker TEXT NOT NULL)');
        $connection->exec('CREATE TABLE form_configuration_version_fields (id INTEGER PRIMARY KEY AUTOINCREMENT, version_id INTEGER NOT NULL, field_key TEXT NOT NULL, control_name TEXT NOT NULL, control_type TEXT NOT NULL, display_label TEXT NOT NULL, parameter_key TEXT NOT NULL, ordinal INTEGER NOT NULL, is_required INTEGER NOT NULL, max_length INTEGER NOT NULL)');
        $connection->exec('CREATE TABLE form_configuration_field_choices (id INTEGER PRIMARY KEY AUTOINCREMENT, field_id INTEGER NOT NULL, choice_value TEXT NOT NULL, choice_label TEXT NOT NULL)');
        $connection->exec("CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT '{}')");
        $connection->exec("INSERT INTO form_configurations (id, public_id, display_name, created_at, updated_at) VALUES (1, '01a0ec00-07c0-70e5-be5f-969fce035077', 'Contact', '2026-10-04T10:00:00.000000Z', '2026-10-04T10:00:00.000000Z')");
        $connection->exec("INSERT INTO form_configuration_versions (id, form_id, version_number, state, revision, recipient, subject, published_at, created_at, updated_at) VALUES (1, 1, 1, 'active', 1, 'owner@example.test', 'Contact', '2026-10-04T10:00:00.000000Z', '2026-10-04T10:00:00.000000Z', '2026-10-04T10:00:00.000000Z')");
        $connection->exec("INSERT INTO form_configuration_version_pages (version_id, host, path, form_marker) VALUES (1, 'example.test', '/contact', 'contactForm')");
        $connection->exec("INSERT INTO form_configuration_version_fields (version_id, field_key, control_name, control_type, display_label, parameter_key, ordinal, is_required, max_length) VALUES (1, 'message', 'message', 'textarea', 'Message', 'message', 0, 1, 10000)");
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class FormChangeObservationStorageResolver implements \Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver
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
