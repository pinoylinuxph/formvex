<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\Exception\InstallationFailure;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use Formvex\Spoke\Domain\Release\UpgradeMaintenanceState;
use JsonException;
use RuntimeException;
use Throwable;

final class LocalUpgradeMaintenanceStore implements UpgradeMaintenanceStore
{
    public function current(PrivateStoragePaths $paths): ?UpgradeMaintenanceState
    {
        $file = $paths->upgradeMaintenanceFile();
        if (!is_file($file) || is_link($file)) {
            return null;
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('The upgrade maintenance state could not be read.');
        }
        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('The upgrade maintenance state is invalid.', 0, $failure);
        }
        if (!is_array($data)) {
            throw new RuntimeException('The upgrade maintenance state is invalid.');
        }

        return $this->state($data);
    }

    public function begin(PrivateStoragePaths $paths, string $operationId, string $currentRelease, string $targetRelease, string $currentSchema, string $targetSchema, DateTimeImmutable $startedAt, DateTimeImmutable $drainDeadlineAt): UpgradeMaintenanceState
    {
        if ($this->current($paths) !== null) {
            throw new InstallationFailure('upgrade_in_progress');
        }

        return $this->write($paths, new UpgradeMaintenanceState($operationId, $currentRelease, $targetRelease, $currentSchema, $targetSchema, 'draining', $startedAt, $drainDeadlineAt));
    }

    public function transition(PrivateStoragePaths $paths, UpgradeMaintenanceState $state, string $nextState): UpgradeMaintenanceState
    {
        $current = $this->current($paths);
        if ($current === null || $current->operationId !== $state->operationId) {
            throw new InstallationFailure('upgrade_state_invalid');
        }

        return $this->write($paths, $state->withState($nextState));
    }

    public function clear(PrivateStoragePaths $paths, string $operationId): void
    {
        $current = $this->current($paths);
        if ($current === null) {
            return;
        }
        if ($current->operationId !== $operationId) {
            throw new InstallationFailure('upgrade_state_invalid');
        }
        if (!unlink($paths->upgradeMaintenanceFile())) {
            throw new RuntimeException('The upgrade maintenance state could not be cleared.');
        }
    }

    private function state(mixed $data): UpgradeMaintenanceState
    {
        if (!is_array($data)) {
            throw new RuntimeException('The upgrade maintenance state is invalid.');
        }
        foreach (['operation_id', 'current_release', 'target_release', 'current_schema', 'target_schema', 'state', 'started_at', 'drain_deadline_at'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new RuntimeException('The upgrade maintenance state is invalid.');
            }
        }
        try {
            $startedAt = new DateTimeImmutable($data['started_at'], new DateTimeZone('UTC'));
            $deadline = new DateTimeImmutable($data['drain_deadline_at'], new DateTimeZone('UTC'));
        } catch (Throwable $failure) {
            throw new RuntimeException('The upgrade maintenance timestamps are invalid.', 0, $failure);
        }

        return new UpgradeMaintenanceState($data['operation_id'], $data['current_release'], $data['target_release'], $data['current_schema'], $data['target_schema'], $data['state'], $startedAt->setTimezone(new DateTimeZone('UTC')), $deadline->setTimezone(new DateTimeZone('UTC')));
    }

    private function write(PrivateStoragePaths $paths, UpgradeMaintenanceState $state): UpgradeMaintenanceState
    {
        try {
            $contents = json_encode([
                'operation_id' => $state->operationId,
                'current_release' => $state->currentRelease,
                'target_release' => $state->targetRelease,
                'current_schema' => $state->currentSchema,
                'target_schema' => $state->targetSchema,
                'state' => $state->state,
                'started_at' => $state->startedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'drain_deadline_at' => $state->drainDeadlineAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $failure) {
            throw new RuntimeException('The upgrade maintenance state could not be encoded.', 0, $failure);
        }
        $temporary = $paths->runtime . DIRECTORY_SEPARATOR . '.upgrade-maintenance-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0o600) || !rename($temporary, $paths->upgradeMaintenanceFile())) {
            @unlink($temporary);
            throw new RuntimeException('The upgrade maintenance state could not be written.');
        }

        return $state;
    }
}
