<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\Contract\StorageSettingsRepository;
use Formvex\Spoke\Domain\Storage\Contract\StorageUsageReader;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use Formvex\Spoke\Domain\Storage\StorageState;
use Formvex\Spoke\Domain\Storage\StorageUsage;
use Formvex\Spoke\Domain\Storage\SubmissionExportData;
use Formvex\Spoke\Infrastructure\Filesystem\BoundedOperationalLogWriter;
use Formvex\Spoke\Infrastructure\Filesystem\CsvExportWriter;
use Formvex\Spoke\Infrastructure\Filesystem\LocalStorageCapacityGuard;
use Formvex\Spoke\Infrastructure\Filesystem\LocalStorageUsageReader;
use PHPUnit\Framework\TestCase;

final class StorageBoundaryTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'formvex-storage-' . bin2hex(random_bytes(8));
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/temporary', 'runtime'] as $directory) {
            mkdir($this->temporaryRoot . DIRECTORY_SEPARATOR . $directory, 0o700, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
    }

    public function testStorageSettingsUseApprovedDefaultsAndBounds(): void
    {
        $defaults = StorageSettings::defaults();

        self::assertSame(2000000000, $defaults->allowanceBytes);
        self::assertSame(80, $defaults->normalWarningPercent);
        self::assertSame(90, $defaults->criticalWarningPercent);
        self::assertSame(2, $defaults->allowanceGigabytes());
        self::assertSame(10, new StorageSettings(10000000000, 1, 99)->allowanceGigabytes());

        $this->expectExceptionMessage('between 1 GB and 10 GB');
        new StorageSettings(999999999, 80, 90);
    }

    public function testCapacityGuardRejectsUnavailableFullAndPhysicalFullStates(): void
    {
        $paths = $this->paths();
        $settings = new StorageSettings(1000000000, 80, 90);
        $settingsRepository = new class ($settings) implements StorageSettingsRepository {
            public function __construct(private readonly StorageSettings $settings)
            {
            }

            public function get(PrivateStoragePaths $paths): StorageSettings
            {
                return $this->settings;
            }

            public function save(PrivateStoragePaths $paths, StorageSettings $settings, DateTimeImmutable $now): void
            {
            }
        };

        $unavailable = new LocalStorageCapacityGuard($settingsRepository, new FixedUsageReader(StorageUsage::unavailable($settings)));
        self::assertSame('measurement_unavailable', $unavailable->evaluate($paths)->code);

        $full = new LocalStorageCapacityGuard($settingsRepository, new FixedUsageReader(new StorageUsage(1000000000, 1000000000, 100, StorageState::FULL, 1000)));
        self::assertSame('logical_allowance_reached', $full->evaluate($paths)->code);

        $physical = new LocalStorageCapacityGuard($settingsRepository, new FixedUsageReader(new StorageUsage(1, 1000000000, 1, StorageState::NORMAL, 0)));
        self::assertSame('physical_storage_full', $physical->evaluate($paths)->code);
    }

    public function testUsageAccountingIncludesApprovedFilesAndExcludesBackupsAndNonExports(): void
    {
        $paths = $this->paths();
        file_put_contents($paths->databaseFile(), str_repeat('d', 11));
        file_put_contents($paths->databaseFile() . '-wal', str_repeat('w', 7));
        file_put_contents($paths->databaseFile() . '-shm', str_repeat('s', 5));
        file_put_contents($paths->logs . '/worker.log', str_repeat('l', 13));
        file_put_contents($paths->diagnostics . '/status.json', str_repeat('i', 17));
        file_put_contents($paths->exports . '/active.csv', str_repeat('e', 19));
        file_put_contents($paths->exports . '/temporary.txt', str_repeat('x', 23));
        file_put_contents($paths->manualBackups . '/backup.zip', str_repeat('b', 29));

        $usage = new LocalStorageUsageReader()->read($paths, new StorageSettings(1000000000, 80, 90));

        self::assertSame(23, $usage->breakdown['Database and SQLite journals']);
        self::assertSame(13, $usage->breakdown['Operational logs']);
        self::assertSame(17, $usage->breakdown['Diagnostics']);
        self::assertSame(19, $usage->breakdown['Active exports']);
        self::assertSame(72, $usage->liveBytes);
        self::assertSame(StorageState::NORMAL, $usage->state);
    }

    public function testCsvWriterNeutralizesFormulaCellsAndPublishesPrivateFile(): void
    {
        $paths = $this->paths();
        $writer = new CsvExportWriter();
        $size = $writer->write($paths, '01a0f2ef-1098-733f-bf9d-26dc71a7c07e', new SubmissionExportData([
            [
                'accepted_at' => '2026-10-01T00:00:00.000000Z',
                'form' => 'Contact, form',
                'configuration_version' => '2',
                'record_type' => 'Visitor submission',
                'classification' => 'Normal',
                'lifecycle' => 'Active review',
                'delivery' => 'Sent',
                'delivery_outcome' => 'Accepted by SMTP transport',
                'handled_at' => '',
                'trashed_at' => '',
                'restored_at' => '',
                'fields' => ['message' => '=SUM(A1:A2)', 'name' => 'A "quoted" value'],
            ],
        ], [
            ['key' => 'message', 'label' => 'Message'],
            ['key' => 'name', 'label' => 'Name'],
        ], 1));
        $file = $paths->exports . '/01a0f2ef-1098-733f-bf9d-26dc71a7c07e.csv';
        $contents = (string) file_get_contents($file);

        self::assertGreaterThan(0, $size);
        self::assertStringContainsString("'=SUM(A1:A2)", $contents);
        self::assertStringContainsString('"A ""quoted"" value"', $contents);
        self::assertStringContainsString("\r\n", $contents);
        self::assertSame(0o600, fileperms($file) & 0o777);
    }

    public function testOperationalLogsRotateAtFivePrivateFiles(): void
    {
        $paths = $this->paths();
        $writer = new BoundedOperationalLogWriter();
        for ($index = 1; $index <= 5; $index++) {
            file_put_contents($paths->logs . '/capacity.log' . ($index === 1 ? '' : '.' . $index), str_repeat('x', BoundedOperationalLogWriter::MAX_BYTES_PER_FILE));
        }

        $writer->append($paths, 'capacity', 'capacity.logical_allowance_reached');

        self::assertFileExists($paths->logs . '/capacity.log');
        self::assertFileExists($paths->logs . '/capacity.log.5');
        self::assertFileDoesNotExist($paths->logs . '/capacity.log.6');
        self::assertLessThan(BoundedOperationalLogWriter::MAX_BYTES_PER_FILE, filesize($paths->logs . '/capacity.log'));
    }

    private function paths(): PrivateStoragePaths
    {
        return new PrivateStoragePaths(
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

final class FixedUsageReader implements StorageUsageReader
{
    public function __construct(private readonly StorageUsage $usage)
    {
    }

    public function read(PrivateStoragePaths $paths, StorageSettings $settings): StorageUsage
    {
        return $this->usage;
    }
}
