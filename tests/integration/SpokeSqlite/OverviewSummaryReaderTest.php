<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoOverviewSummaryReader;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OverviewSummaryReaderTest extends TestCase
{
    private string $temporaryRoot;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-overview-' . bin2hex(random_bytes(8));
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
        $connection = $this->connection();
        $connection->exec('CREATE TABLE form_configurations (id INTEGER PRIMARY KEY, public_id TEXT NOT NULL, display_name TEXT NOT NULL, deleted_at TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $connection->exec('CREATE TABLE form_configuration_versions (id INTEGER PRIMARY KEY, form_id INTEGER NOT NULL, version_number INTEGER NOT NULL, state TEXT NOT NULL)');
        $connection->exec('CREATE TABLE form_configuration_evidence (version_id INTEGER PRIMARY KEY, end_to_end_status TEXT NULL)');
        $connection->exec('CREATE TABLE submissions (id INTEGER PRIMARY KEY, classification TEXT NOT NULL, state TEXT NOT NULL)');
        $connection->exec('CREATE TABLE delivery_jobs (id INTEGER PRIMARY KEY, state TEXT NOT NULL)');
        $connection->exec("INSERT INTO form_configurations VALUES
            (1, 'form-active', 'Active form', NULL, '2026-10-01', '2026-10-01'),
            (2, 'form-needs-qualification', 'Unqualified form', NULL, '2026-10-01', '2026-10-01'),
            (3, 'form-disabled', 'Disabled form', NULL, '2026-10-01', '2026-10-01'),
            (4, 'form-draft', 'Draft form', NULL, '2026-10-01', '2026-10-01'),
            (5, 'form-trashed', 'Trashed form', '2026-10-01', '2026-10-01', '2026-10-01')");
        $connection->exec("INSERT INTO form_configuration_versions VALUES
            (11, 1, 1, 'active'),
            (21, 2, 1, 'active'),
            (31, 3, 1, 'disabled'),
            (41, 4, 0, 'draft'),
            (51, 5, 1, 'active')");
        $connection->exec("INSERT INTO form_configuration_evidence VALUES (11, 'sent'), (21, 'not_run'), (31, 'sent'), (51, 'sent')");
        $connection->exec("INSERT INTO submissions VALUES
            (1, 'normal', 'accepted'),
            (2, 'normal', 'handled'),
            (3, 'suspected_spam', 'accepted'),
            (4, 'suspected_spam', 'trashed')");
        $connection->exec("INSERT INTO delivery_jobs VALUES
            (1, 'queued'), (2, 'processing'), (3, 'sent'), (4, 'failed'), (5, 'uncertain')");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testAggregatesCurrentStateWithoutLoadingVisitorOrRecipientValues(): void
    {
        $reader = new PdoOverviewSummaryReader();

        $forms = $reader->forms($this->paths);
        self::assertSame(4, $forms->total);
        self::assertSame(2, $forms->active);
        self::assertSame(2, $forms->needsAttention);

        $submissions = $reader->submissions($this->paths);
        self::assertSame(3, $submissions->total);
        self::assertSame(2, $submissions->unhandled);
        self::assertSame(1, $submissions->suspectedSpam);

        $delivery = $reader->delivery($this->paths);
        self::assertSame(5, $delivery->total);
        self::assertSame(1, $delivery->queued);
        self::assertSame(1, $delivery->processing);
        self::assertSame(1, $delivery->sent);
        self::assertSame(1, $delivery->failed);
        self::assertSame(1, $delivery->uncertain);
    }

    public function testMissingSourceFailsClosedInsteadOfReturningHealthyOrZero(): void
    {
        $this->connection()->exec('DROP TABLE delivery_jobs');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overview source is unavailable');

        new PdoOverviewSummaryReader()->delivery($this->paths);
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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
