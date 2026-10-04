<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeSqlite;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportMetadata;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Persistence\PdoDiagnosticReportRepository;
use Formvex\Spoke\Migrations\Version000021CreateDiagnosticReports;
use PDO;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportRepositoryTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-diagnostic-repository-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
        $connection = $this->connection();
        $connection->exec("CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, resource_public_id TEXT NULL, metadata_json TEXT NOT NULL DEFAULT '{}')");
        new Version000021CreateDiagnosticReports()->up($connection);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReportsArePublishedListedAndExpiredByMetadata(): void
    {
        $repository = new PdoDiagnosticReportRepository();
        $generated = new DateTimeImmutable('2026-10-05T00:00:00Z', new DateTimeZone('UTC'));
        $metadata = new DiagnosticReportMetadata('01a0f744-d824-7576-ac66-c5f492429fd1', 'admin', 'diagnostic-report-01a0f744-d824-7576-ac66-c5f492429fd1.json', $generated, $generated->modify('+15 minutes'), 512);

        $repository->publish($this->paths, $metadata, $generated);

        self::assertSame($metadata->publicId, $repository->latest($this->paths)?->publicId);
        self::assertCount(1, $repository->available($this->paths));
        self::assertCount(1, $repository->expired($this->paths, $generated->modify('+16 minutes'), 10));
        $repository->remove($this->paths, $metadata->publicId, 'admin', 'expiry_cleanup', $generated->modify('+16 minutes'));
        self::assertNull($repository->latest($this->paths));
        self::assertSame('spoke.diagnostic_report.expired', $this->connection()->query('SELECT event_name FROM audit_events ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    private function connection(): PDO
    {
        return new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
