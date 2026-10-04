<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Backup;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Formvex\Spoke\Domain\Backup\BackupArtifact;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Domain\Backup\Contract\BackupArchiveStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;

final readonly class ZipBackupArchiveStore implements BackupArchiveStore
{
    private const FORMAT_VERSION = 1;

    public function __construct(private string $publicRoot)
    {
    }

    public function currentSchemaVersion(PrivateStoragePaths $paths): string
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $connection->query('SELECT MAX(version) FROM schema_migrations');
        $version = $statement === false ? null : $statement->fetchColumn();

        return is_string($version) && preg_match('/\A\d{6}\z/', $version) === 1 ? $version : '000000';
    }

    public function create(PrivateStoragePaths $paths, string $publicId, BackupKind $kind, DateTimeImmutable $createdAt): BackupArtifact
    {
        $this->requireZip();
        $this->ensureDirectory($paths->temporaryBackups);
        $candidate = $paths->temporaryBackups . DIRECTORY_SEPARATOR . '.' . $publicId . '.zip.tmp';
        $snapshot = $paths->temporaryBackups . DIRECTORY_SEPARATOR . '.' . $publicId . '.sqlite.tmp';
        $entries = [];

        try {
            $schemaVersion = $this->snapshotDatabase($paths->databaseFile(), $snapshot);
            $entries[] = $this->entry($snapshot, 'database/formvex.sqlite');
            $this->collectTree($paths->secrets, 'secrets', $entries);
            $this->collectTree($this->brandingDirectory(), 'branding', $entries);
            if (is_file($paths->markerFile()) && !is_link($paths->markerFile())) {
                $entries[] = $this->entry($paths->markerFile(), 'installation-state.json');
            }
            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'archive_id' => $publicId,
                'kind' => $kind->value,
                'created_at' => $createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
                'schema_version' => $schemaVersion,
                'entries' => $entries,
            ];
            $this->writeZip($paths, $candidate, $snapshot, $entries, $manifest);
            $manifestSchema = $this->verify($paths, $candidate);
            $targetPrefix = match ($kind) {
                BackupKind::PRE_UPGRADE => 'pre_upgrade/',
                BackupKind::SCHEDULED => 'scheduled/',
                BackupKind::MANUAL => 'manual/',
            };
            $targetKey = $targetPrefix . $publicId . '.zip';
            $target = $this->archivePath($paths, $targetKey);
            $this->ensureDirectory(dirname($target));
            if (!rename($candidate, $target)) {
                throw new BackupFailure('backup_publish_failed', 'The backup was verified but could not be published safely.');
            }

            return new BackupArtifact($targetKey, (int) filesize($target), (string) hash_file('sha256', $target), $manifestSchema);
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new BackupFailure('backup_creation_failed', 'The backup could not be created safely. Existing completed backups were not changed.', $failure);
        } finally {
            @unlink($candidate);
            @unlink($snapshot);
        }
    }

    public function verify(PrivateStoragePaths $paths, string $archivePath): string
    {
        $this->requireZip();
        $real = realpath($archivePath);
        if ($real === false || is_link($archivePath) || !is_file($real) || !$this->isPrivateArchive($paths, $real)) {
            throw new BackupFailure('archive_path_invalid', 'The archive path is not a completed private backup archive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($real) !== true) {
            throw new BackupFailure('archive_invalid', 'The archive could not be opened as a ZIP file.');
        }
        try {
            $manifestContents = $zip->getFromName('manifest.json');
            if (!is_string($manifestContents)) {
                throw new BackupFailure('manifest_missing', 'The archive is missing its integrity manifest.');
            }
            $manifest = json_decode($manifestContents, true, 20, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format_version'] ?? null) !== self::FORMAT_VERSION || !is_string($manifest['schema_version'] ?? null) || !is_array($manifest['entries'] ?? null)) {
                throw new BackupFailure('manifest_invalid', 'The archive manifest is invalid or unsupported.');
            }
            $names = [];
            $required = ['database/formvex.sqlite'];
            foreach ($manifest['entries'] as $entry) {
                if (!is_array($entry) || !is_string($entry['name'] ?? null) || !is_int($entry['size'] ?? null) || !is_string($entry['sha256'] ?? null)) {
                    throw new BackupFailure('manifest_invalid', 'The archive manifest contains an invalid entry.');
                }
                $name = $entry['name'];
                if (!$this->allowedEntry($name) || isset($names[$name]) || $zip->locateName($name) === false) {
                    throw new BackupFailure('archive_entry_invalid', 'The archive contains an unsupported or duplicate entry.');
                }
                $names[$name] = true;
                [$size, $sha256] = $this->streamEntry($zip, $name);
                if ($size !== $entry['size'] || !hash_equals($entry['sha256'], $sha256)) {
                    throw new BackupFailure('checksum_mismatch', 'The archive entry checksum does not match its manifest.');
                }
            }
            foreach ($required as $entry) {
                if (!isset($names[$entry])) {
                    throw new BackupFailure('required_entry_missing', 'The archive is missing the database snapshot required for restore.');
                }
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || ($name !== 'manifest.json' && !isset($names[$name]))) {
                    throw new BackupFailure('archive_entry_invalid', 'The archive contains an entry outside the approved backup contents.');
                }
                if ($name !== 'manifest.json' && $this->isSymlink($zip, $index)) {
                    throw new BackupFailure('archive_entry_invalid', 'The archive contains a symbolic link or unsupported file type.');
                }
            }

            return $manifest['schema_version'];
        } catch (BackupFailure $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new BackupFailure('archive_invalid', 'The archive manifest could not be validated.', $failure);
        } finally {
            $zip->close();
        }
    }

    public function archivePath(PrivateStoragePaths $paths, string $storageKey): string
    {
        if (!preg_match('/\A(?:manual|pre_upgrade|scheduled)\/[0-9a-f-]{16,80}\.zip\z/', $storageKey)) {
            throw new BackupFailure('archive_key_invalid', 'The backup storage key is invalid.');
        }
        $base = match (true) {
            str_starts_with($storageKey, 'manual/') => $paths->manualBackups,
            str_starts_with($storageKey, 'scheduled/') => $paths->scheduledBackups,
            default => $paths->preUpgradeBackups,
        };

        if ($base === '') {
            throw new BackupFailure('backup_storage_unavailable', 'The selected private backup directory is not configured.');
        }

        return $base . DIRECTORY_SEPARATOR . substr($storageKey, strpos($storageKey, '/') + 1);
    }

    public function delete(PrivateStoragePaths $paths, string $storageKey): void
    {
        $path = $this->archivePath($paths, $storageKey);
        if (is_link($path)) {
            throw new BackupFailure('backup_delete_failed', 'The selected backup is not a regular private archive.');
        }
        if (is_file($path) && !unlink($path)) {
            throw new BackupFailure('backup_delete_failed', 'The selected backup could not be deleted. No other backup was changed.');
        }
    }

    public function restore(PrivateStoragePaths $paths, string $archivePath): void
    {
        $schemaVersion = $this->verify($paths, $archivePath);
        if (!preg_match('/\A\d{6}\z/', $schemaVersion) || (int) $schemaVersion > 19) {
            throw new BackupFailure('schema_incompatible', 'The archive schema is newer than this installation can restore.');
        }
        $staging = $paths->temporaryBackups . DIRECTORY_SEPARATOR . 'restore-' . bin2hex(random_bytes(10));
        $this->ensureDirectory($staging);
        try {
            $this->extract($archivePath, $staging);
            $stagedDatabase = $staging . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'formvex.sqlite';
            if (!is_file($stagedDatabase) || is_link($stagedDatabase)) {
                throw new BackupFailure('restore_staging_invalid', 'The staged database snapshot is unavailable.');
            }
            $temporaryDatabase = $paths->database . DIRECTORY_SEPARATOR . '.restore-' . bin2hex(random_bytes(8)) . '.sqlite';
            if (!copy($stagedDatabase, $temporaryDatabase) || !chmod($temporaryDatabase, 0o600) || !$this->validDatabase($temporaryDatabase)) {
                @unlink($temporaryDatabase);
                throw new BackupFailure('restore_database_invalid', 'The staged database could not be validated.');
            }
            if (!rename($temporaryDatabase, $paths->databaseFile())) {
                @unlink($temporaryDatabase);
                throw new BackupFailure('restore_database_publish_failed', 'The restored database could not be published safely.');
            }
            $this->replaceTree($staging . DIRECTORY_SEPARATOR . 'secrets', $paths->secrets);
            $this->replaceTree($staging . DIRECTORY_SEPARATOR . 'branding', $this->brandingDirectory());
            $stagedMarker = $staging . DIRECTORY_SEPARATOR . 'installation-state.json';
            if (is_file($stagedMarker) && !is_link($stagedMarker) && (!copy($stagedMarker, $paths->markerFile()) || !chmod($paths->markerFile(), 0o600))) {
                throw new BackupFailure('restore_marker_publish_failed', 'The restored installation marker could not be published safely.');
            }
            $this->reconcile($paths->databaseFile());
        } finally {
            $this->removeTree($staging);
        }
    }

    private function snapshotDatabase(string $source, string $target): string
    {
        if (!is_file($source) || is_link($source)) {
            throw new BackupFailure('database_unavailable', 'The local database is not available for backup.');
        }
        $connection = new PDO('sqlite:' . $source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->exec('PRAGMA wal_checkpoint(PASSIVE)');
        $statement = $connection->query('SELECT MAX(version) FROM schema_migrations');
        $version = $statement === false ? '000000' : (string) ($statement->fetchColumn() ?: '000000');
        $statement = null;
        $escaped = str_replace("'", "''", $target);
        $connection->exec("VACUUM INTO '{$escaped}'");

        return $version;
    }

    /** @param list<array{name: string, size: int, sha256: string}> $entries */
    private function collectTree(string $directory, string $prefix, array &$entries): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1));
            $entries[] = $this->entry($file->getPathname(), $prefix . '/' . $relative);
        }
    }

    /** @return array{name: string, size: int, sha256: string} */
    private function entry(string $path, string $name): array
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($size === false || $sha256 === false || !$this->allowedEntry($name)) {
            throw new BackupFailure('backup_entry_failed', 'A required backup entry could not be read safely.');
        }

        return ['name' => $name, 'size' => (int) $size, 'sha256' => $sha256];
    }

    /**
     * @param list<array{name: string, size: int, sha256: string}> $entries
     * @param array<string, mixed> $manifest
     */
    private function writeZip(PrivateStoragePaths $paths, string $target, string $snapshot, array $entries, array $manifest): void
    {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupFailure('zip_create_failed', 'The backup ZIP could not be created.');
        }
        try {
            foreach ($entries as $entry) {
                $source = $this->sourcePath($paths, $snapshot, $entry['name']);
                if ($source === null || !$zip->addFile($source, $entry['name'])) {
                    throw new BackupFailure('zip_entry_failed', 'The backup ZIP could not include a required entry.');
                }
            }
            $contents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!$zip->addFromString('manifest.json', $contents)) {
                throw new BackupFailure('manifest_write_failed', 'The backup manifest could not be written.');
            }
            if ($zip->close() !== true) {
                throw new BackupFailure('zip_close_failed', 'The backup ZIP could not be finalized.');
            }
        } catch (Throwable $failure) {
            $zip->close();
            if ($failure instanceof BackupFailure) {
                throw $failure;
            }
            throw new BackupFailure('zip_create_failed', 'The backup ZIP could not be finalized.', $failure);
        }
    }

    private function sourcePath(PrivateStoragePaths $paths, string $snapshot, string $name): ?string
    {
        if ($name === 'database/formvex.sqlite') {
            return is_file($snapshot) && !is_link($snapshot) ? $snapshot : null;
        }
        if ($name === 'installation-state.json') {
            return $paths->markerFile();
        }
        if (str_starts_with($name, 'secrets/')) {
            $path = $paths->secrets . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($name, 8));
        } elseif (str_starts_with($name, 'branding/')) {
            $path = $this->brandingDirectory() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($name, 9));
        } else {
            return null;
        }

        return is_file($path) && !is_link($path) ? $path : null;
    }

    /** @return array{int, string} */
    private function streamEntry(ZipArchive $zip, string $name): array
    {
        $stream = $zip->getStream($name);
        if (!is_resource($stream)) {
            throw new BackupFailure('archive_entry_unreadable', 'A backup entry could not be read safely.');
        }
        $hash = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new BackupFailure('archive_entry_unreadable', 'A backup entry could not be read safely.');
            }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);

        return [$size, hash_final($hash)];
    }

    private function extract(string $archivePath, string $staging): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new BackupFailure('archive_invalid', 'The backup archive could not be opened.');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || $name === 'manifest.json') {
                    continue;
                }
                $target = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
                $parent = dirname($target);
                $this->ensureDirectory($parent);
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new BackupFailure('restore_extract_failed', 'A backup entry could not be staged.');
                }
                $output = fopen($target, 'x');
                if ($output === false) {
                    fclose($stream);
                    throw new BackupFailure('restore_extract_failed', 'A backup entry could not be staged.');
                }
                stream_copy_to_stream($stream, $output);
                fclose($stream);
                fclose($output);
                chmod($target, 0o600);
            }
        } finally {
            $zip->close();
        }
    }

    private function replaceTree(string $source, string $target): void
    {
        $this->ensureDirectory($target);
        $this->removeChildren($target);
        if (!is_dir($source)) {
            return;
        }
        $this->copyTree($source, $target);
    }

    private function copyTree(string $source, string $target): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->isLink()) {
                throw new BackupFailure('restore_entry_invalid', 'The restore archive contains an unsupported file type.');
            }
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(rtrim($source, DIRECTORY_SEPARATOR)) + 1));
            $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($file->isDir()) {
                $this->ensureDirectory($destination);
            } elseif (!copy($file->getPathname(), $destination) || !chmod($destination, 0o600)) {
                throw new BackupFailure('restore_entry_publish_failed', 'A restored private file could not be published safely.');
            }
        }
    }

    private function reconcile(string $database): void
    {
        $connection = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z');
        $connection->beginTransaction();
        try {
            $connection->exec('DELETE FROM admin_sessions');
            $connection->exec('DELETE FROM form_qualification_capabilities');
            $connection->exec('UPDATE local_administrators SET session_invalidation_generation = session_invalidation_generation + 1 WHERE singleton_id = 1');
            $statement = $connection->prepare("UPDATE delivery_jobs SET state = 'queued', due_at = :due_at, lease_token = NULL, lease_expires_at = NULL, last_error_code = 'restored_snapshot_claim', last_outcome = 'uncertain', updated_at = :updated_at WHERE state = 'processing'");
            $statement->execute(['due_at' => $now, 'updated_at' => $now]);
            if ($this->hasColumn($connection, 'submissions', 'restored_at')) {
                $connection->prepare('UPDATE submissions SET restored_at = :restored_at WHERE restored_at IS NULL')->execute(['restored_at' => $now]);
            }
            $audit = $connection->prepare('INSERT INTO audit_events (event_name, outcome, occurred_at, resource_type, metadata_json) VALUES (:event_name, :outcome, :occurred_at, :resource_type, :metadata_json)');
            $audit->execute([
                'event_name' => 'spoke.backup.restore_reconciled',
                'outcome' => 'success',
                'occurred_at' => $now,
                'resource_type' => 'backup_restore',
                'metadata_json' => json_encode(['stale_snapshot_duplicate_risk' => true], JSON_THROW_ON_ERROR),
            ]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw new BackupFailure('restore_reconciliation_failed', 'The restored state could not be reconciled safely.', $failure);
        }
    }

    private function validDatabase(string $path): bool
    {
        try {
            $connection = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $connection->query('PRAGMA integrity_check');
            if ($statement === false || $statement->fetchColumn() !== 'ok') {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function allowedEntry(string $name): bool
    {
        return $name === 'database/formvex.sqlite' || $name === 'installation-state.json' || preg_match('/\A(?:secrets|branding)\/[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\z/', $name) === 1;
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        $attributes = $zip->getExternalAttributesIndex($index, $opsys, $attr);

        return $attributes && $opsys === ZipArchive::OPSYS_UNIX && (is_int($attr) && (($attr & 0xF000) === 0xA000));
    }

    private function isPrivateArchive(PrivateStoragePaths $paths, string $path): bool
    {
        foreach ([$paths->manualBackups, $paths->preUpgradeBackups, $paths->scheduledBackups, $paths->temporaryBackups] as $directory) {
            $boundary = rtrim((string) realpath($directory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($boundary !== DIRECTORY_SEPARATOR && str_starts_with($path, $boundary)) {
                return true;
            }
        }

        return false;
    }

    private function brandingDirectory(): string
    {
        return rtrim($this->publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'branding';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && (!mkdir($directory, 0o700, true) || !is_dir($directory))) {
            throw new BackupFailure('backup_storage_unavailable', 'The private backup directory is not available.');
        }
        chmod($directory, 0o700);
    }

    private function removeChildren(string $directory): void
    {
        $iterator = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo) {
                $this->removeTree($file->getPathname());
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $this->removeChildren($path);
        @rmdir($path);
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new BackupFailure('zip_extension_missing', 'PHP ext-zip is required to create or restore backups.');
        }
    }

    private function hasColumn(PDO $connection, string $table, string $column): bool
    {
        $statement = $connection->prepare('SELECT 1 FROM pragma_table_info(:table_name) WHERE name = :column_name LIMIT 1');
        $statement->execute(['table_name' => $table, 'column_name' => $column]);

        return $statement->fetchColumn() !== false;
    }
}
