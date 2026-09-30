<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormResolution;

use Formvex\Contracts\Spoke\FormResolution\FormResolutionResponse;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class FormResolutionController extends AbstractController
{
    public function __construct(
        private readonly FormConfigurationService $formConfigurationService,
        private readonly InstallationSettingsStore $settingsStore,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly IdentifierGenerator $identifierGenerator,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/formvex/api/v1/forms/resolve', name: 'spoke_api_v1_forms_resolve', methods: ['GET'])]
    public function resolve(Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        $origin = is_string($origin) && trim($origin) !== '' ? $origin : null;

        try {
            if (!$request->isSecure() || strlen((string) $request->getQueryString()) > 8192) {
                return $this->safeNotFound();
            }

            $query = $request->query->all();

            foreach (array_keys($query) as $key) {
                if (!in_array($key, ['schema_version', 'page_path', 'form_marker'], true)) {
                    return $this->safeNotFound();
                }
            }

            $schemaVersion = $query['schema_version'] ?? null;
            $pagePath = $query['page_path'] ?? null;
            $formMarker = $query['form_marker'] ?? null;

            if (!is_string($schemaVersion) || $schemaVersion !== '1' || !is_string($pagePath) || !is_string($formMarker) || strlen($pagePath) > 2048 || strlen($formMarker) > 120) {
                return $this->safeNotFound();
            }

            $host = $request->getHost();
            $allowedOrigins = $this->allowedOrigins();

            if ($origin !== null && !in_array($origin, $allowedOrigins, true)) {
                return $this->safeNotFound();
            }

            $resolution = $this->formConfigurationService->resolvePublic(
                $this->runtimeConfiguration->applicationRoot,
                $host,
                $pagePath,
                $formMarker,
            );

            if ($resolution === null) {
                return $this->safeNotFound($origin);
            }

            $response = new JsonResponse(new FormResolutionResponse(
                $resolution->publicFormId,
                $resolution->configurationVersion,
                $resolution->formMarker,
                $resolution->captchaEnabled,
                $resolution->captchaProvider,
                $resolution->captchaSiteKey,
            )->toArray(), Response::HTTP_OK);
            $this->headers($response, $origin);

            return $response;
        } catch (Throwable) {
            return $this->safeNotFound($origin);
        }
    }

    private function safeNotFound(?string $origin = null): JsonResponse
    {
        $response = new JsonResponse([
            'schema_version' => FormResolutionResponse::SCHEMA_VERSION,
            'error' => [
                'code' => 'form_unavailable',
                'message' => 'Formvex could not find an approved form configuration for this page.',
                'request_id' => $this->identifierGenerator->uuidV7($this->clock->now()),
            ],
        ], Response::HTTP_NOT_FOUND);
        $this->headers($response, $origin);

        return $response;
    }

    private function headers(Response $response, ?string $origin): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Origin');

        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
        $settings = $this->settingsStore->get($paths);
        $origins = [];

        foreach ([$settings->bareDomain, $settings->wwwAlias] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $origins[] = 'https://' . $alias;
            }
        }

        return $origins;
    }
}
