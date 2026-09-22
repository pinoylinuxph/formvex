<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\AdministratorRecord;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Administration\ThrottleState;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;

interface LocalAdministratorStore
{
    public function findAdministrator(PrivateStoragePaths $paths): ?AdministratorRecord;

    public function bootstrapAdministrator(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): void;

    public function resetAdministrator(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): void;

    public function recordLoginSuccess(PrivateStoragePaths $paths, DateTimeImmutable $now): void;

    public function replacePasswordHash(
        PrivateStoragePaths $paths,
        string $passwordHash,
    ): void;

    public function changePassword(
        PrivateStoragePaths $paths,
        string $passwordHash,
        DateTimeImmutable $now,
    ): int;

    public function findSession(PrivateStoragePaths $paths, string $sessionIdHash): ?SessionRecord;

    public function saveSession(PrivateStoragePaths $paths, SessionRecord $session): void;

    public function touchSession(
        PrivateStoragePaths $paths,
        string $sessionIdHash,
        DateTimeImmutable $lastActivityAt,
        DateTimeImmutable $expiresAt,
    ): void;

    public function replaceSessionCsrfToken(
        PrivateStoragePaths $paths,
        string $sessionIdHash,
        string $csrfTokenHash,
    ): void;

    public function revokeSession(PrivateStoragePaths $paths, string $sessionIdHash): void;

    public function revokeAllSessions(PrivateStoragePaths $paths): void;

    public function findThrottleState(PrivateStoragePaths $paths, string $throttleKeyHash): ?ThrottleState;

    public function recordLoginFailure(
        PrivateStoragePaths $paths,
        string $throttleKeyHash,
        DateTimeImmutable $now,
        int $windowSeconds,
        int $maximumFailures,
        int $cooldownSeconds,
    ): ThrottleState;

    public function clearLoginFailures(PrivateStoragePaths $paths, string $throttleKeyHash): void;

    public function recordAuditEvent(
        PrivateStoragePaths $paths,
        string $eventName,
        string $outcome,
        DateTimeImmutable $occurredAt,
    ): void;
}
