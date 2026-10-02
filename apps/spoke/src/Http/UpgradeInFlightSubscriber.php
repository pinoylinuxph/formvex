<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class UpgradeInFlightSubscriber implements EventSubscriberInterface
{
    private const LEASE_ATTRIBUTE = '_formvex_upgrade_inflight_lease';

    public function __construct(
        private SpokeRuntimeConfiguration $runtimeConfiguration,
        private SpokeStorageResolver $storageResolver,
        private UpgradeInFlightTracker $tracker,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 110],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getMethod() !== 'POST' || !$this->isPublicSubmission($event->getRequest()->getPathInfo())) {
            return;
        }
        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            $lease = $this->tracker->begin($paths, 'request');
            if ($lease !== null) {
                $event->getRequest()->attributes->set(self::LEASE_ATTRIBUTE, $lease);
            }
        } catch (Throwable) {
            // The recovery/maintenance boundary remains authoritative if lease accounting is unavailable.
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $lease = $event->getRequest()->attributes->get(self::LEASE_ATTRIBUTE);
        if (!is_string($lease) || $lease === '') {
            return;
        }
        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            $this->tracker->finish($paths, $lease);
        } catch (Throwable) {
            // A stale lease remains visible to the bounded drain timeout and requires operator review.
        }
    }

    private function isPublicSubmission(string $path): bool
    {
        return (str_starts_with($path, '/formvex/api/v1/forms/') && str_ends_with($path, '/submissions'))
            || $path === '/formvex/api/v1/qualification/submissions';
    }
}
