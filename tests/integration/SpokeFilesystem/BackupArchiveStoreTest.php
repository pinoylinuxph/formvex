<?php

declare(strict_types=1);

namespace Formvex\Tests\Integration\SpokeFilesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Infrastructure\Backup\ZipBackupArchiveStore;
use PDO;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupArchiveStoreTest extends TestCase
{
    private string $root;
    private string $publicRoot;
    private PrivateStoragePaths $paths;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/formvex-backup-archive-' . bin2hex(random_bytes(8));
        $this->publicRoot = $this->root . '/public';
        foreach (['database', 'secrets', 'logs', 'exports', 'diagnostics', 'backups/scheduled', 'backups/manual', 'backups/pre-upgrade', 'backups/temporary', 'runtime', 'public/branding'] as $directory) {
            mkdir($this->root . '/' . $directory, 0o700, true);
        }
        $this->paths = new PrivateStoragePaths($this->root, $this->root . '/database', $this->root . '/secrets', $this->root . '/logs', $this->root . '/exports', $this->root . '/diagnostics', $this->root . '/backups/scheduled', $this->root . '/backups/manual', $this->root . '/backups/temporary', $this->root . '/runtime', $this->root . '/backups/pre-upgrade');
        $connection = new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec("CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)");
        $connection->exec("INSERT INTO schema_migrations VALUES ('000021', '2026-10-01T00:00:00.000000Z')");
        $connection->exec('CREATE TABLE admin_sessions (id INTEGER PRIMARY KEY)');
        $connection->exec('CREATE TABLE form_qualification_capabilities (id INTEGER PRIMARY KEY)');
        $connection->exec('CREATE TABLE local_administrators (singleton_id INTEGER PRIMARY KEY, session_invalidation_generation INTEGER NOT NULL)');
        $connection->exec('INSERT INTO local_administrators VALUES (1, 0)');
        $connection->exec('CREATE TABLE delivery_jobs (state TEXT NOT NULL, due_at TEXT NOT NULL, lease_token TEXT NULL, lease_expires_at TEXT NULL, last_error_code TEXT NULL, last_outcome TEXT NULL, updated_at TEXT NOT NULL)');
        $connection->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL, outcome TEXT NOT NULL, occurred_at TEXT NOT NULL, resource_type TEXT NULL, metadata_json TEXT NOT NULL)');
        file_put_contents($this->paths->secrets . '/smtp-password-a.php', "<?php return 'private';");
        chmod($this->paths->secrets . '/smtp-password-a.php', 0o600);
        file_put_contents($this->publicRoot . '/branding/logo-1-aaaaaaaaaaaaaaaa.png', 'branding');
        file_put_contents($this->paths->runtime . '/installation-state.json', '{"installation_id":"test","schema_version":"000021"}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCreatesPrivateVerifiedManifestAndRejectsPublicPath(): void
    {
        $store = new ZipBackupArchiveStore($this->publicRoot);
        $id = '01a0f744-d824-7576-ac66-c5f492429fd1';
        $artifact = $store->create($this->paths, $id, BackupKind::MANUAL, new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC')));
        $archive = $store->archivePath($this->paths, $artifact->storageKey);

        self::assertSame('000021', $store->verify($this->paths, $archive));
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        self::assertIsString($zip->getFromName('manifest.json'));
        self::assertIsString($zip->getFromName('database/formvex.sqlite'));
        self::assertIsString($zip->getFromName('secrets/smtp-password-a.php'));
        self::assertIsString($zip->getFromName('branding/logo-1-aaaaaaaaaaaaaaaa.png'));
        $zip->close();

        $this->expectException(BackupFailure::class);
        $store->verify($this->paths, $this->publicRoot . '/branding/logo-1-aaaaaaaaaaaaaaaa.png');
    }

    public function testRestoresCurrentSchemaAndReconcilesState(): void
    {
        $store = new ZipBackupArchiveStore($this->publicRoot);
        $artifact = $store->create($this->paths, '01a0f744-d824-7576-ac66-c5f492429fd2', BackupKind::MANUAL, new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC')));

        $store->restore($this->paths, $store->archivePath($this->paths, $artifact->storageKey));

        $connection = new PDO('sqlite:' . $this->paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::assertSame('000021', $store->currentSchemaVersion($this->paths));
        self::assertSame('1', (string) $connection->query('SELECT session_invalidation_generation FROM local_administrators WHERE singleton_id = 1')->fetchColumn());
        self::assertSame('spoke.backup.restore_reconciled', $connection->query('SELECT event_name FROM audit_events ORDER BY id DESC LIMIT 1')->fetchColumn());
    }

    public function testRejectsSchemaNewerThanCurrentRestoreBoundary(): void
    {
        $store = new ZipBackupArchiveStore($this->publicRoot);
        $artifact = $store->create($this->paths, '01a0f744-d824-7576-ac66-c5f492429fd2', BackupKind::MANUAL, new DateTimeImmutable('2026-10-01T00:00:00.000000Z', new DateTimeZone('UTC')));
        $archive = $store->archivePath($this->paths, $artifact->storageKey);
        $futureArchive = $this->paths->manualBackups . '/01a0f744-d824-7576-ac66-c5f492429fd3.zip';
        self::assertTrue(copy($archive, $futureArchive));

        $zip = new ZipArchive();
        self::assertTrue($zip->open($futureArchive) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 20, JSON_THROW_ON_ERROR);
        $manifest['schema_version'] = '000022';
        self::assertTrue($zip->deleteName('manifest.json'));
        self::assertTrue($zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('newer than this installation can restore');
        $store->restore($this->paths, $futureArchive);
    }

    public function testRejectsTraversalEntryOutsideApprovedArchiveContents(): void
    {
        $archive = $this->paths->manualBackups . '/malicious-traversal.zip';
        $database = (string) file_get_contents($this->paths->databaseFile());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        self::assertTrue($zip->addFromString('database/formvex.sqlite', $database));
        self::assertTrue($zip->addFromString('../outside.txt', 'must not be extracted'));
        self::assertTrue($zip->addFromString('manifest.json', json_encode([
            'format_version' => 1,
            'archive_id' => 'malicious-traversal',
            'kind' => 'manual',
            'created_at' => '2026-10-01T00:00:00.000000Z',
            'schema_version' => '000016',
            'entries' => [[
                'name' => 'database/formvex.sqlite',
                'size' => strlen($database),
                'sha256' => hash('sha256', $database),
            ]],
        ], JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());

        $this->expectException(BackupFailure::class);
        $this->expectExceptionMessage('outside the approved backup contents');
        new ZipBackupArchiveStore($this->publicRoot)->verify($this->paths, $archive);
    }

    public function testRejectsManifestChecksumMismatch(): void
    {
        $archive = $this->paths->manualBackups . '/bad-checksum.zip';
        $database = (string) file_get_contents($this->paths->databaseFile());
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        self::assertTrue($zip->addFromString('database/formvex.sqlite', $database));
        self::assertTrue($zip->addFromString('manifest.json', json_encode([
            'format_version' => 1,
            'archive_id' => 'bad-checksum',
            'kind' => 'manual',
            'created_at' => '2026-10-01T00:00:00.000000Z',
            'schema_version' => '000016',
            'entries' => [[
                'name' => 'database/formvex.sqlite',
                'size' => strlen($database),
                'sha256' => str_repeat('0', 64),
            ]],
        ], JSON_THROW_ON_ERROR)));
        self::assertTrue($zip->close());

        try {
            new ZipBackupArchiveStore($this->publicRoot)->verify($this->paths, $archive);
            self::fail('A checksum mismatch must be rejected.');
        } catch (BackupFailure $failure) {
            self::assertSame('checksum_mismatch', $failure->failureCode);
        }
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
