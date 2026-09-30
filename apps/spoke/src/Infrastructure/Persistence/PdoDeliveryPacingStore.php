<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryPacingStore;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use PDO;
use RuntimeException;
use Throwable;

final class PdoDeliveryPacingStore implements DeliveryPacingStore
{
    public function tryConsume(PrivateStoragePaths $paths, DateTimeImmutable $now, int $limitPerMinute): bool
    {
        $connection = new PDO('sqlite:' . $paths->databaseFile(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $bucket = intdiv($now->getTimestamp(), 60) * 60;

        try {
            $connection->exec('BEGIN IMMEDIATE TRANSACTION');
            $query = $connection->query('SELECT window_start, attempt_count FROM delivery_pacing WHERE singleton_id = 1');
            $row = $query === false ? false : $query->fetch(PDO::FETCH_ASSOC);
            $windowStart = is_array($row) && (is_int($row['window_start'] ?? null) || (is_string($row['window_start'] ?? null) && ctype_digit($row['window_start']))) ? (int) $row['window_start'] : 0;
            $attemptCount = is_array($row) && (is_int($row['attempt_count'] ?? null) || (is_string($row['attempt_count'] ?? null) && ctype_digit($row['attempt_count']))) ? (int) $row['attempt_count'] : 0;
            $count = $windowStart === $bucket ? $attemptCount : 0;

            if ($count >= $limitPerMinute) {
                $connection->rollBack();

                return false;
            }

            $statement = $connection->prepare('UPDATE delivery_pacing SET window_start = :window_start, attempt_count = :attempt_count WHERE singleton_id = 1');
            $statement->execute(['window_start' => $bucket, 'attempt_count' => $count + 1]);
            $connection->commit();

            return true;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw new RuntimeException('The delivery pacing state could not be updated safely.', 0, $failure);
        }
    }
}
