<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Backup\RecoveryHold;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use JsonException;
use RuntimeException;
use Throwable;

final class LocalRecoveryHoldStore implements RecoveryHoldStore
{
    public function current(PrivateStoragePaths $paths): ?RecoveryHold
    {
        if (!is_file($paths->recoveryHoldFile()) || is_link($paths->recoveryHoldFile())) {
            return null;
        }
        $contents = file_get_contents($paths->recoveryHoldFile());
        if ($contents === false) {
            throw new RuntimeException('The recovery hold could not be read.');
        }

        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The recovery hold is invalid.', 0, $failure);
        }
        if (!is_array($data) || !is_string($data['operation'] ?? null) || !is_string($data['reason'] ?? null) || !is_string($data['started_at'] ?? null)) {
            throw new RuntimeException('The recovery hold is invalid.');
        }

        try {
            $startedAt = new DateTimeImmutable($data['started_at']);
        } catch (Throwable $failure) {
            throw new RuntimeException('The recovery hold timestamp is invalid.', 0, $failure);
        }

        return new RecoveryHold($data['operation'], $startedAt->setTimezone(new DateTimeZone('UTC')), $data['reason']);
    }

    public function activate(PrivateStoragePaths $paths, DateTimeImmutable $startedAt, string $operation, string $reason): void
    {
        try {
            $contents = json_encode([
                'operation' => $operation,
                'started_at' => $startedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $failure) {
            throw new RuntimeException('The recovery hold could not be encoded.', 0, $failure);
        }
        $temporary = $paths->runtime . DIRECTORY_SEPARATOR . '.recovery-hold-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0o600) || !rename($temporary, $paths->recoveryHoldFile())) {
            @unlink($temporary);
            throw new RuntimeException('The recovery hold could not be activated. No live state was changed.');
        }
    }

    public function clear(PrivateStoragePaths $paths): void
    {
        if (is_file($paths->recoveryHoldFile()) && !unlink($paths->recoveryHoldFile())) {
            throw new RuntimeException('The recovery hold could not be cleared. The installation remains unavailable.');
        }
    }
}
