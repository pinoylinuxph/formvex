<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\ReleaseCheckState;
use Formvex\Spoke\Infrastructure\Persistence\PdoReleaseCheckRepository;
use Formvex\Spoke\Migrations\Version000020CreateReleaseNotifications;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReleaseCheckRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-release-check-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
        $connection = $this->connection();
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT \'{}\')');
        new Version000020CreateReleaseNotifications()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReleaseChecksAreDisabledUntilExplicitlyEnabled(): void
    {
        $repository = new PdoReleaseCheckRepository();
        $settings = $repository->settings($this->paths);

        self::assertFalse($settings->enabled);

        $now = new DateTimeImmutable('2026-10-04T00:00:00Z', new DateTimeZone('UTC'));
        $repository->saveSettings($this->paths, true, $now);
        self::assertTrue($repository->settings($this->paths)->enabled);
        self::assertSame('spoke.release_check.settings_changed', $this->connection()->query("SELECT event_name FROM audit_events WHERE event_name = 'spoke.release_check.settings_changed'")->fetchColumn());
    }

    public function testReleaseStateAndAcknowledgementAreStoredWithoutRawMetadata(): void
    {
        $repository = new PdoReleaseCheckRepository();
        $now = new DateTimeImmutable('2026-10-04T00:00:00Z', new DateTimeZone('UTC'));
        $state = new ReleaseCheckState('available', '1.0.1', '1.1.0', 'important', '1.0.0', 'https://updates.example.com/notes', 'https://updates.example.com/package.zip', str_repeat('b', 64), $now, $now, $now, null, null, null, null);

        $repository->saveState($this->paths, $state, $now);
        $repository->acknowledge($this->paths, '1.1.0', 'admin', $now);
        $stored = $repository->state($this->paths);

        self::assertSame('1.1.0', $stored->acknowledgedVersion);
        self::assertSame('spoke.release_check.acknowledged', $this->connection()->query("SELECT event_name FROM audit_events WHERE event_name = 'spoke.release_check.acknowledged'")->fetchColumn());
        self::assertStringNotContainsString('visitor', (string) $this->connection()->query('SELECT metadata_json FROM audit_events ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->root . '/database/formvex.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function removeDirectory(string $directory): void
    {
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
