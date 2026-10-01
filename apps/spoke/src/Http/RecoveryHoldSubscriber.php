<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final readonly class RecoveryHoldSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SpokeRuntimeConfiguration $runtimeConfiguration,
        private SpokeStorageResolver $storageResolver,
        private RecoveryHoldStore $recoveryHoldStore,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 100]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        $recoveryStateUnavailable = false;
        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            if ($this->recoveryHoldStore->current($paths) === null) {
                return;
            }
        } catch (Throwable) {
            $recoveryStateUnavailable = true;
        }

        if ($recoveryStateUnavailable && !str_starts_with($path, '/formvex')) {
            return;
        }

        if (str_starts_with($path, '/formvex/api/')) {
            $response = new JsonResponse([
                'schema_version' => 1,
                'error' => [
                    'code' => 'installation_unavailable',
                    'message' => $recoveryStateUnavailable
                        ? 'This installation is temporarily unavailable while its recovery state is being checked.'
                        : 'This installation is temporarily unavailable while a server-side recovery operation completes.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        } else {
            $response = new Response(
                $recoveryStateUnavailable
                    ? 'This installation is temporarily unavailable while its recovery state is being checked.'
                    : 'This installation is temporarily unavailable while a server-side recovery operation completes.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $event->setResponse($response);
    }
}
