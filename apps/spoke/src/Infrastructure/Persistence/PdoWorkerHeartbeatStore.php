<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Formvex\Spoke\Domain\Delivery\Contract\WorkerHeartbeatStore;
use Formvex\Spoke\Domain\Delivery\DeliveryWorkerResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;

final class PdoWorkerHeartbeatStore implements WorkerHeartbeatStore
{
    public function recordSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now, DeliveryWorkerResult $result): void
    {
        $this->record($paths, $now, $result, true);
    }

    public function recordFailure(PrivateStoragePaths $paths, DateTimeImmutable $now, DeliveryWorkerResult $result): void
    {
        $this->record($paths, $now, $result, false);
    }

    private function record(PrivateStoragePaths $paths, DateTimeImmutable $now, DeliveryWorkerResult $result, bool $success): void
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $timestamp = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $statement = $connection->prepare(
            'UPDATE delivery_worker_heartbeat SET last_success_at = CASE WHEN :success = 1 THEN :success_at ELSE last_success_at END, last_result = :result, last_claimed = :claimed, last_sent = :sent, last_failed = :failed, last_uncertain = :uncertain, last_deferred = :deferred, updated_at = :updated_at WHERE singleton_id = 1',
        );
        $statement->execute([
            'success' => $success ? 1 : 0,
            'success_at' => $timestamp,
            'result' => $success && $result->succeeded ? 'success' : 'failure',
            'claimed' => $result->claimed,
            'sent' => $result->sent,
            'failed' => $result->failed,
            'uncertain' => $result->uncertain,
            'deferred' => $result->deferred,
            'updated_at' => $timestamp,
        ]);
    }
}
