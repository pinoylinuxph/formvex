<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Administration;

use DateInterval;
use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\AuthenticatedSession;
use Formvex\Spoke\Domain\Administration\Contract\LocalAdministratorStore;
use Formvex\Spoke\Domain\Administration\Contract\PasswordHasher;
use Formvex\Spoke\Domain\Administration\Contract\SecurityTokenGenerator;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Administration\Contract\TemporaryPasswordGenerator;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\PasswordPolicy;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Administration\TemporaryPasswordResult;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;

final readonly class LocalAdministratorService
{
    private const ADMINISTRATOR_IDENTIFIER = 'admin';

    private const IDLE_TIMEOUT_SECONDS = 1800;

    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private LocalAdministratorStore $store,
        private PasswordHasher $passwordHasher,
        private SecurityTokenGenerator $securityTokenGenerator,
        private TemporaryPasswordGenerator $temporaryPasswordGenerator,
        private PasswordPolicy $passwordPolicy,
        private Clock $clock,
        private InstallationSettingsStore $installationSettingsStore,
    ) {
    }

    public function bootstrap(string $applicationRoot): TemporaryPasswordResult
    {
        $paths = $this->authorizedCommandPaths($applicationRoot);

        if ($this->store->findAdministrator($paths) !== null) {
            $this->recordAudit($paths, 'spoke.administrator.bootstrap_rejected', 'administrator_exists');

            throw new AdministratorFailure('administrator_already_exists');
        }

        $temporaryPassword = $this->temporaryPasswordGenerator->generate();
        $this->passwordPolicy->validate($temporaryPassword);
        $this->store->bootstrapAdministrator(
            $paths,
            $this->passwordHasher->hash($temporaryPassword),
            $this->clock->now(),
        );

        return new TemporaryPasswordResult($temporaryPassword);
    }

    public function reset(string $applicationRoot): TemporaryPasswordResult
    {
        $paths = $this->authorizedCommandPaths($applicationRoot);

        if ($this->store->findAdministrator($paths) === null) {
            $this->recordAudit($paths, 'spoke.administrator.reset_rejected', 'administrator_missing');

            throw new AdministratorFailure('administrator_missing');
        }

        $temporaryPassword = $this->temporaryPasswordGenerator->generate();
        $this->passwordPolicy->validate($temporaryPassword);
        $this->store->resetAdministrator(
            $paths,
            $this->passwordHasher->hash($temporaryPassword),
            $this->clock->now(),
        );

        return new TemporaryPasswordResult($temporaryPassword);
    }

    public function authenticate(
        string $applicationRoot,
        string $loginIdentifier,
        string $password,
        string $clientAddress,
    ): AuthenticatedSession {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->clock->now();
        $throttleSettings = $this->installationSettingsStore->get($paths)->loginThrottle;
        $throttleKeyHash = $this->securityTokenGenerator->hash($clientAddress);
        $throttle = $this->store->findThrottleState($paths, $throttleKeyHash);

        if ($throttle !== null && $throttle->isCoolingDown($now)) {
            throw new AdministratorFailure(
                'login_rate_limited',
                max(1, $throttle->cooldownUntil?->getTimestamp() - $now->getTimestamp()),
            );
        }

        $administrator = $this->store->findAdministrator($paths);
        $passwordHash = $administrator === null
            ? $this->passwordHasher->dummyHash()
            : $administrator->passwordHash;
        $passwordMatches = $this->passwordHasher->verify($password, $passwordHash);

        if ($administrator === null || $loginIdentifier !== self::ADMINISTRATOR_IDENTIFIER || !$passwordMatches) {
            $failure = $this->store->recordLoginFailure(
                $paths,
                $throttleKeyHash,
                $now,
                $throttleSettings->windowSeconds(),
                $throttleSettings->maximumFailures,
                $throttleSettings->cooldownSeconds(),
            );
            $retryAfter = $failure->cooldownUntil === null
                ? null
                : max(1, $failure->cooldownUntil->getTimestamp() - $now->getTimestamp());

            throw new AdministratorFailure('invalid_credentials', $retryAfter);
        }

        $this->store->clearLoginFailures($paths, $throttleKeyHash);

        if ($this->passwordHasher->needsRehash($administrator->passwordHash)) {
            $this->store->replacePasswordHash($paths, $this->passwordHasher->hash($password));
        }

        $this->store->recordLoginSuccess($paths, $now);

        return $this->createSession($paths, $administrator->sessionInvalidationGeneration, $administrator->mustChangePassword, $now);
    }

    public function changePassword(
        string $applicationRoot,
        string $sessionId,
        string $csrfToken,
        string $newPassword,
    ): AuthenticatedSession {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->clock->now();
        $session = $this->validSession($paths, $sessionId, $now);

        if ($this->securityTokenGenerator->hash($csrfToken) !== $session->csrfTokenHash) {
            throw new AdministratorFailure('csrf_invalid');
        }

        $this->passwordPolicy->validate($newPassword);
        $generation = $this->store->changePassword(
            $paths,
            $this->passwordHasher->hash($newPassword),
            $now,
        );
        return $this->createSession($paths, $generation, false, $now);
    }

    public function session(string $applicationRoot, string $sessionId): ?SessionRecord
    {
        if ($sessionId === '') {
            return null;
        }

        $paths = $this->storageResolver->resolve($applicationRoot);
        $now = $this->clock->now();
        $session = $this->store->findSession($paths, $this->securityTokenGenerator->hash($sessionId));

        if ($session === null || $session->revoked || $session->expiresAt <= $now) {
            return null;
        }

        $administrator = $this->store->findAdministrator($paths);

        if ($administrator === null || $administrator->sessionInvalidationGeneration !== $session->sessionInvalidationGeneration) {
            return null;
        }

        $expiresAt = $now->add(new DateInterval('PT' . self::IDLE_TIMEOUT_SECONDS . 'S'));
        $this->store->touchSession($paths, $session->sessionIdHash, $now, $expiresAt);

        return new SessionRecord(
            $session->sessionIdHash,
            $session->csrfTokenHash,
            $session->sessionInvalidationGeneration,
            $session->createdAt,
            $now,
            $expiresAt,
            false,
            $administrator->mustChangePassword,
        );
    }

    public function refreshCsrfToken(string $applicationRoot, string $sessionId): string
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $session = $this->validSession($paths, $sessionId, $this->clock->now());
        $csrfToken = $this->securityTokenGenerator->generate();
        $this->store->replaceSessionCsrfToken(
            $paths,
            $session->sessionIdHash,
            $this->securityTokenGenerator->hash($csrfToken),
        );

        return $csrfToken;
    }

    public function logout(string $applicationRoot, string $sessionId, string $csrfToken): void
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $session = $this->store->findSession($paths, $this->securityTokenGenerator->hash($sessionId));

        if ($session === null) {
            return;
        }

        if ($this->securityTokenGenerator->hash($csrfToken) !== $session->csrfTokenHash) {
            throw new AdministratorFailure('csrf_invalid');
        }

        $this->store->revokeSession($paths, $session->sessionIdHash);
        $this->recordAudit($paths, 'spoke.administrator.logout', 'success');
    }

    public function csrfTokenMatches(SessionRecord $session, string $csrfToken): bool
    {
        return hash_equals($session->csrfTokenHash, $this->securityTokenGenerator->hash($csrfToken));
    }

    private function authorizedCommandPaths(string $applicationRoot): PrivateStoragePaths
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $this->storageResolver->assertOperatorOwns($paths);

        return $paths;
    }

    private function validSession(PrivateStoragePaths $paths, string $sessionId, DateTimeImmutable $now): SessionRecord
    {
        $session = $this->store->findSession($paths, $this->securityTokenGenerator->hash($sessionId));

        if ($session === null || $session->revoked || $session->expiresAt <= $now) {
            throw new AdministratorFailure('authentication_required');
        }

        $administrator = $this->store->findAdministrator($paths);

        if ($administrator === null || $administrator->sessionInvalidationGeneration !== $session->sessionInvalidationGeneration) {
            throw new AdministratorFailure('authentication_required');
        }

        return $session;
    }

    private function createSession(
        PrivateStoragePaths $paths,
        int $generation,
        bool $mustChangePassword,
        DateTimeImmutable $now,
    ): AuthenticatedSession {
        $sessionId = $this->securityTokenGenerator->generate();
        $csrfToken = $this->securityTokenGenerator->generate();
        $this->store->saveSession(
            $paths,
            new SessionRecord(
                $this->securityTokenGenerator->hash($sessionId),
                $this->securityTokenGenerator->hash($csrfToken),
                $generation,
                $now,
                $now,
                $now->add(new DateInterval('PT' . self::IDLE_TIMEOUT_SECONDS . 'S')),
                false,
                $mustChangePassword,
            ),
        );

        return new AuthenticatedSession($sessionId, $csrfToken, $mustChangePassword);
    }

    private function recordAudit(PrivateStoragePaths $paths, string $eventName, string $outcome): void
    {
        $this->store->recordAuditEvent($paths, $eventName, $outcome, $this->clock->now());
    }
}
