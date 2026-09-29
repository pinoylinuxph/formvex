<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormDiscovery;

use Formvex\Contracts\Spoke\FormDiscovery\FormDiscoveryResponse;
use Formvex\Spoke\Application\FormDiscovery\FormDiscoveryService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class FormDiscoveryController extends AbstractController
{
    public function __construct(
        private readonly FormDiscoveryService $discoveryService,
        private readonly InstallationSettingsStore $settingsStore,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly IdentifierGenerator $identifierGenerator,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/formvex/api/v1/discovery/redeem', name: 'spoke_api_v1_discovery_redeem', methods: ['POST', 'OPTIONS'])]
    public function redeem(Request $request): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            $response = new Response('', Response::HTTP_NO_CONTENT);
            $this->headers($response, $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

            return $response;
        }

        try {
            if (!$request->isSecure() || $origin === null || !$this->originAllowed($origin)) {
                return $this->failure('discovery_not_authorized', 'Formvex could not authorize discovery for this page.', $origin);
            }

            $contentType = $request->headers->get('Content-Type') ?? '';

            if (!str_starts_with(strtolower($contentType), 'application/json')) {
                return $this->failure('discovery_request_invalid', 'The discovery request must use the application/json content type.', $origin);
            }

            $body = $request->getContent();
            $settings = $this->settings();

            if ($body === '' || strlen($body) > $settings->discoveryPayloadLimitBytes) {
                return $this->failure('discovery_payload_too_large', 'The discovery metadata exceeds the configured request limit.', $origin);
            }

            $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || array_is_list($decoded)) {
                return $this->failure('discovery_request_invalid', 'The discovery request must be a JSON object.', $origin);
            }

            $payload = [];

            foreach ($decoded as $key => $value) {
                if (!is_string($key) || !in_array($key, ['schema_version', 'capability', 'page_path', 'forms'], true)) {
                    return $this->failure('discovery_request_invalid', 'The discovery request contains an unsupported property.', $origin);
                }

                $payload[$key] = $value;
            }

            $token = $payload['capability'] ?? null;
            unset($payload['capability']);

            if (!is_string($token) || $token === '') {
                return $this->failure('discovery_not_authorized', 'The discovery authorization is missing or invalid.', $origin);
            }

            $candidate = $this->discoveryService->redeem(
                $this->runtimeConfiguration->applicationRoot,
                $token,
                $request->getHost(),
                $payload,
                strlen($body),
            );
            $response = new JsonResponse(
                new FormDiscoveryResponse($candidate->candidateId, $candidate->status->value, $candidate->expiresAt->format(DATE_ATOM))->toArray(),
                Response::HTTP_CREATED,
            );
            $this->headers($response, $origin);

            return $response;
        } catch (JsonException) {
            return $this->failure('discovery_request_invalid', 'The discovery request is not valid JSON.', $origin);
        } catch (FormDiscoveryFailure $failure) {
            return $this->failure($failure->failureCode, $failure->getMessage(), $origin);
        } catch (Throwable) {
            return $this->failure('discovery_unavailable', 'Formvex could not process discovery for this page. Return to the portal and start a new discovery session.', $origin);
        }
    }

    private function settings(): \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings
    {
        return $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));
    }

    private function originAllowed(?string $origin): bool
    {
        if ($origin === null) {
            return true;
        }

        $settings = $this->settings();

        foreach ([$settings->bareDomain, $settings->wwwAlias] as $alias) {
            if (is_string($alias) && $alias !== '' && $origin === 'https://' . $alias) {
                return true;
            }
        }

        return false;
    }

    private function failure(string $code, string $message, ?string $origin): JsonResponse
    {
        $response = new JsonResponse([
            'schema_version' => FormDiscoveryResponse::SCHEMA_VERSION,
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $this->identifierGenerator->uuidV7($this->clock->now()),
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->headers($response, $origin);

        return $response;
    }

    private function headers(Response $response, ?string $origin): void
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Origin');

        if ($origin !== null) {
            try {
                if ($this->originAllowed($origin)) {
                    $response->headers->set('Access-Control-Allow-Origin', $origin);
                }
            } catch (Throwable) {
                // A storage failure must not turn a safe JSON error into an uncaught response failure.
            }
        }
    }
}
