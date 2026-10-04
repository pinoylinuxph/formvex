<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeFilesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Diagnostics\LocalDiagnosticReportStore;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportStoreTest extends TestCase
{
    private string $root;

    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-diagnostic-report-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReportIsWrittenAtomicallyAndCanBeReadBack(): void
    {
        $report = $this->report();
        $store = new LocalDiagnosticReportStore();
        $key = 'diagnostic-report-01a0f744-d824-7576-ac66-c5f492429fd1.json';

        $size = $store->write($this->paths, $report, $key);

        self::assertSame(strlen($report->toJson()), $size);
        self::assertSame('safe', $store->read($this->paths, $key)->sections[0]['entries'][0]['value']);
        self::assertSame(0o600, fileperms($this->paths->diagnostics . '/' . $key) & 0o777);
        $store->delete($this->paths, $key);
        self::assertFileDoesNotExist($this->paths->diagnostics . '/' . $key);
    }

    public function testTraversalAndSymlinkStorageKeysAreRejected(): void
    {
        $store = new LocalDiagnosticReportStore();
        $this->expectException(DiagnosticReportFailure::class);
        $store->write($this->paths, $this->report(), '../outside.json');
    }

    public function testExistingHardLinkedReportCannotBeReplaced(): void
    {
        $store = new LocalDiagnosticReportStore();
        $key = 'diagnostic-report-01a0f744-d824-7576-ac66-c5f492429fd1.json';
        $store->write($this->paths, $this->report(), $key);
        self::assertTrue(link($this->paths->diagnostics . '/' . $key, $this->paths->diagnostics . '/report-alias.json'));

        $this->expectException(DiagnosticReportFailure::class);
        $store->write($this->paths, $this->report(), $key);
    }

    private function report(): DiagnosticReport
    {
        $generated = new DateTimeImmutable('2026-10-05T00:00:00.000000Z', new DateTimeZone('UTC'));

        return new DiagnosticReport(
            '01a0f744-d824-7576-ac66-c5f492429fd1',
            $generated,
            $generated->modify('+15 minutes'),
            [['key' => 'health', 'label' => 'Health', 'status' => 'Available', 'entries' => [['key' => 'status', 'label' => 'Status', 'value' => 'safe']]]],
        );
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
