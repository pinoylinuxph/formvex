<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormChangeObservation;

use Formvex\Spoke\Application\FormChangeObservation\FormChangeObservationService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class FormChangeObservationController extends AbstractController
{
    public function __construct(
        private readonly FormChangeObservationRequestResolver $requestResolver,
        private readonly FormChangeObservationService $observationService,
        private readonly InstallationSettingsStore $settingsStore,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/api/v1/forms/{publicFormId}/change-observations', name: 'spoke_api_v1_form_change_observation', methods: ['POST', 'OPTIONS'])]
    public function observe(Request $request, string $publicFormId): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request, $origin);
        }

        try {
            if (!$this->validPublicId($publicFormId)
                || !$request->isSecure()
                || !$this->publicHostAllowed($request->getHost())
                || ($origin !== null && !$this->originAllowed($origin))) {
                return $this->safeResponse($origin);
            }

            $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
            if (!str_starts_with($contentType, 'application/json')) {
                return $this->safeResponse($origin);
            }

            $settings = $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));
            $contentLength = $request->headers->get('Content-Length');
            if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > $settings->discoveryPayloadLimitBytes) {
                return $this->safeResponse($origin);
            }

            $body = $request->getContent();
            if ($body === '' || strlen($body) > $settings->discoveryPayloadLimitBytes) {
                return $this->safeResponse($origin);
            }

            $this->observationService->observe(
                $this->runtimeConfiguration->applicationRoot,
                $publicFormId,
                $this->requestResolver->resolve($body),
            );
        } catch (Throwable) {
            // The website form must remain usable when observation storage is unavailable.
        }

        return $this->safeResponse($origin);
    }

    private function preflight(Request $request, ?string $origin): Response
    {
        $allowed = false;

        try {
            $allowed = $origin !== null && $this->publicHostAllowed($request->getHost()) && $this->originAllowed($origin);
        } catch (Throwable) {
            $allowed = false;
        }

        $response = $this->safeResponse($allowed ? $origin : null);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }

    private function safeResponse(?string $origin): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Vary', 'Origin');

        if ($origin !== null) {
            try {
                if ($this->originAllowed($origin)) {
                    $response->headers->set('Access-Control-Allow-Origin', $origin);
                }
            } catch (Throwable) {
                // Never reflect an origin when the private settings cannot be read.
            }
        }

        return $response;
    }

    private function validPublicId(string $publicFormId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $publicFormId) === 1;
    }

    private function publicHostAllowed(string $host): bool
    {
        $settings = $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));
        $host = strtolower(rtrim($host, '.'));

        return in_array($host, array_values(array_filter([$settings->bareDomain, $settings->wwwAlias], static fn (?string $alias): bool => $alias !== null && $alias !== '')), true);
    }

    private function originAllowed(string $origin): bool
    {
        $settings = $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));

        foreach ([$settings->bareDomain, $settings->wwwAlias] as $alias) {
            if (is_string($alias) && $alias !== '' && $origin === 'https://' . $alias) {
                return true;
            }
        }

        return false;
    }
}
