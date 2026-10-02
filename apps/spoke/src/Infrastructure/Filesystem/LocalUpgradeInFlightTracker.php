<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use JsonException;
use RuntimeException;

final class LocalUpgradeInFlightTracker implements UpgradeInFlightTracker
{
    public function begin(PrivateStoragePaths $paths, string $kind): ?string
    {
        /** @param array{draining: bool, leases: array<string, array{kind: string, started_at: string}>} $state */
        $result = $this->mutate($paths, function (array &$state) use ($kind): ?string {
            return $this->beginInState($state, $kind);
        });
        if ($result !== null && !is_string($result)) {
            throw new RuntimeException('Upgrade in-flight lease state is invalid.');
        }

        return $result;
    }

    public function finish(PrivateStoragePaths $paths, string $leaseId): void
    {
        /** @param array{draining: bool, leases: array<string, array{kind: string, started_at: string}>} $state */
        $this->mutate($paths, function (array &$state) use ($leaseId): null {
            $this->finishInState($state, $leaseId);

            return null;
        });
    }

    public function startDraining(PrivateStoragePaths $paths): void
    {
        $this->mutate($paths, function (array &$state): null {
            $state['draining'] = true;

            return null;
        });
    }

    public function stopDraining(PrivateStoragePaths $paths): void
    {
        $this->mutate($paths, function (array &$state): null {
            $state['draining'] = false;
            $state['leases'] = [];

            return null;
        });
    }

    public function activeCount(PrivateStoragePaths $paths): int
    {
        return count($this->read($paths)['leases']);
    }

    /** @return array{draining: bool, leases: array<string, array{kind: string, started_at: string}>} */
    private function read(PrivateStoragePaths $paths): array
    {
        $file = $this->stateFile($paths);
        if (!is_file($file) || is_link($file)) {
            return ['draining' => false, 'leases' => []];
        }
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException('Upgrade in-flight state could not be read.');
        }
        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException('Upgrade in-flight state is invalid.', 0, $failure);
        }
        if (!is_array($data) || !is_bool($data['draining'] ?? null) || !is_array($data['leases'] ?? null)) {
            throw new RuntimeException('Upgrade in-flight state is invalid.');
        }

        $leases = [];
        foreach ($data['leases'] as $leaseId => $lease) {
            if (!is_string($leaseId) || !is_array($lease) || !is_string($lease['kind'] ?? null) || !is_string($lease['started_at'] ?? null)) {
                throw new RuntimeException('Upgrade in-flight state is invalid.');
            }
            $leases[$leaseId] = ['kind' => $lease['kind'], 'started_at' => $lease['started_at']];
        }

        return ['draining' => $data['draining'], 'leases' => $leases];
    }

    private function mutate(PrivateStoragePaths $paths, callable $callback): mixed
    {
        $file = $this->stateFile($paths);
        $lock = fopen($paths->upgradeInFlightLockFile(), 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Upgrade in-flight state could not be locked.');
        }
        try {
            $state = $this->read($paths);
            $result = $callback($state);
            $this->write($file, $state);

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array{draining: bool, leases: array<string, array{kind: string, started_at: string}>} $state */
    private function write(string $file, array $state): void
    {
        try {
            $contents = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $failure) {
            throw new RuntimeException('Upgrade in-flight state could not be encoded.', 0, $failure);
        }
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0o600) || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Upgrade in-flight state could not be written.');
        }
    }

    private function stateFile(PrivateStoragePaths $paths): string
    {
        return $paths->upgradeInFlightFile();
    }

    /** @param array<mixed> $state */
    private function beginInState(array &$state, string $kind): ?string
    {
        if (!is_bool($state['draining'] ?? null) || !is_array($state['leases'] ?? null)) {
            throw new RuntimeException('Upgrade in-flight state is invalid.');
        }
        if ($state['draining'] === true) {
            return null;
        }
        $leaseId = bin2hex(random_bytes(16));
        $leases = $state['leases'];
        $leases[$leaseId] = [
            'kind' => $kind,
            'started_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
        $state['leases'] = $leases;

        return $leaseId;
    }

    /** @param array<mixed> $state */
    private function finishInState(array &$state, string $leaseId): void
    {
        if (!is_array($state['leases'] ?? null)) {
            throw new RuntimeException('Upgrade in-flight state is invalid.');
        }
        $leases = $state['leases'];
        unset($leases[$leaseId]);
        $state['leases'] = $leases;
    }
}
