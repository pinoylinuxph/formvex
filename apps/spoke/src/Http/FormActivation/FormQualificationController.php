<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\FormActivation;

use Formvex\Contracts\V1\Submission\SubmissionAcceptedResponse;
use Formvex\Contracts\V1\Submission\SubmissionErrorResponse;
use Formvex\Contracts\V1\Submission\SubmissionFieldError;
use Formvex\Spoke\Application\Abuse\SubmissionAbuseService;
use Formvex\Spoke\Application\Branding\BrandingService;
use Formvex\Spoke\Application\FormActivation\FormActivationService;
use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Domain\Submission\SubmissionAccepted;
use Formvex\Spoke\Http\Submission\RateLimitIdentityResolver;
use Formvex\Spoke\Http\Submission\SubmissionRequestResolver;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class FormQualificationController extends AbstractController
{
    public function __construct(
        private readonly FormActivationService $activationService,
        private readonly BrandingService $brandingService,
        private readonly QualificationRequestResolver $qualificationRequestResolver,
        private readonly SubmissionRequestResolver $submissionRequestResolver,
        private readonly InstallationSettingsStore $settingsStore,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly IdentifierGenerator $identifierGenerator,
        private readonly Clock $clock,
        private readonly SubmissionAbuseService $abuseService,
        private readonly AbuseSettingsStore $abuseSettingsStore,
        private readonly RateLimitIdentityResolver $clientIpResolver,
    ) {
    }

    #[Route('/formvex/api/v1/qualification/redeem', name: 'spoke_api_v1_qualification_redeem', methods: ['POST', 'OPTIONS'])]
    public function redeem(Request $request): Response
    {
        $origin = $request->headers->get('Origin');

        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request, $origin);
        }

        try {
            $this->assertBrowserRequest($request, $origin);
            $body = $this->boundedBody($request);
            $decoded = $this->jsonObject($body);
            $token = $decoded['qualification'] ?? null;

            if (!is_string($token) || $token === '') {
                return $this->failure('qualification_not_authorized', 'The qualification authorization is missing or invalid. Start a new qualification session from the Forms portal.', $origin);
            }

            unset($decoded['qualification']);
            $result = $this->activationService->redeemQualification(
                $this->runtimeConfiguration->applicationRoot,
                $token,
                $request->getHost(),
                $decoded,
                strlen($body),
            );
            [$session, $qualificationToken, $version] = $result;

            $response = new JsonResponse([
                'schema_version' => 1,
                'qualification_token' => $qualificationToken,
                'public_form_id' => $version->publicId,
                'configuration_version' => $version->versionNumber,
                'form_marker' => $version->page->formMarker,
                'captcha' => [
                    'enabled' => $version->captchaEnabled,
                    'provider' => 'turnstile',
                    'site_key' => $version->captchaEnabled ? $version->captchaSiteKey : '',
                ],
                'branding' => [
                    'brand_name' => $this->brandingService->viewModel($this->runtimeConfiguration->applicationRoot)['brandName'],
                ],
                'expires_at' => $session->expiresAt->format(DATE_ATOM),
            ], Response::HTTP_CREATED);
            $this->headers($response, $origin);

            return $response;
        } catch (FormActivationFailure $failure) {
            return $this->failure($failure->failureCode, $failure->getMessage(), $origin);
        } catch (InstallationSettingsFailure) {
            return $this->failure('qualification_unavailable', 'The local qualification service could not load its protected settings. Try again after checking the installation.', $origin, Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (Throwable) {
            return $this->failure('qualification_unavailable', 'The local qualification service could not process this request. Return to the portal and start a new qualification session.', $origin, Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/formvex/api/v1/qualification/submissions', name: 'spoke_api_v1_qualification_submission', methods: ['POST', 'OPTIONS'])]
    public function submit(Request $request): Response
    {
        $origin = $request->headers->get('Origin');
        $requestId = $this->identifierGenerator->uuidV7($this->clock->now());

        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request, $origin);
        }

        try {
            $this->assertBrowserRequest($request, $origin);
            $clientIp = $this->clientIpResolver->resolve(
                $request,
                $this->abuseSettingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot))->trustedProxyCidrs,
            );
            $this->abuseService->assertFloodAllowed($this->runtimeConfiguration->applicationRoot, $clientIp);
            $body = $this->boundedBody($request);
            $qualification = $this->qualificationRequestResolver->resolve($body);
            $submission = $this->submissionRequestResolver->resolve($qualification['submissionBody']);
            $accepted = $this->activationService->submitQualification(
                $this->runtimeConfiguration->applicationRoot,
                $qualification['token'],
                $request->getHost(),
                $submission,
                $clientIp,
            );
            $response = new JsonResponse(
                new SubmissionAcceptedResponse($accepted->receiptId, SubmissionAccepted::ACKNOWLEDGEMENT)->toArray(),
                Response::HTTP_ACCEPTED,
            );
            $this->headers($response, $origin);

            return $response;
        } catch (FormActivationFailure $failure) {
            return $this->failure($failure->failureCode, $failure->getMessage(), $origin, $this->activationStatus($failure->failureCode), $requestId, $failure->fieldErrors);
        } catch (SubmissionFailure $failure) {
            return $this->failure($failure->failureCode, $failure->getMessage(), $origin, $this->submissionStatus($failure->failureCode), $requestId, $failure->fieldErrors, $failure->retryAfterSeconds);
        } catch (InstallationSettingsFailure) {
            return $this->failure('qualification_unavailable', 'The local qualification service could not apply its protected settings. The test was not accepted. Try again later.', $origin, Response::HTTP_SERVICE_UNAVAILABLE, $requestId);
        } catch (Throwable) {
            return $this->failure('qualification_unavailable', 'The local qualification service could not process this test. The test was not accepted. Return to the portal and start a new qualification session.', $origin, Response::HTTP_SERVICE_UNAVAILABLE, $requestId);
        }
    }

    private function assertBrowserRequest(Request $request, ?string $origin): void
    {
        if (!$request->isSecure() || $origin === null || !$this->originAllowed($origin) || $origin !== 'https://' . strtolower(rtrim($request->getHost(), '.'))) {
            throw new FormActivationFailure('qualification_not_authorized', 'Qualification is available only from the configured HTTPS website origin. Start a new qualification session from the Forms portal.');
        }

        $contentType = $request->headers->get('Content-Type') ?? '';

        if (!str_starts_with(strtolower($contentType), 'application/json')) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request must use the application/json content type.');
        }
    }

    private function boundedBody(Request $request): string
    {
        $contentLength = $request->headers->get('Content-Length');
        $settings = $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));

        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > $settings->discoveryPayloadLimitBytes) {
            throw new FormActivationFailure('qualification_payload_too_large', 'The qualification request exceeds the configured metadata limit. Reduce the number of detected controls and try again.');
        }

        $body = $request->getContent();

        if ($body === '' || strlen($body) > $settings->discoveryPayloadLimitBytes) {
            throw new FormActivationFailure('qualification_payload_too_large', 'The qualification request exceeds the configured metadata limit. Reduce the number of detected controls and try again.');
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request is not valid JSON. Reload the qualification page and try again.');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification request must be one JSON object. Reload the qualification page and try again.');
        }

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key) || !in_array($key, ['schema_version', 'qualification', 'page_path', 'forms'], true)) {
                throw new FormActivationFailure('qualification_request_invalid', 'The qualification request contains an unsupported property. Update the installed website script and try again.');
            }
        }

        if (($decoded['schema_version'] ?? null) !== 1) {
            throw new FormActivationFailure('qualification_request_invalid', 'The qualification schema version is not supported. Update the installed website script and try again.');
        }

        $object = [];

        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new FormActivationFailure('qualification_request_invalid', 'The qualification request must use named properties. Reload the qualification page and try again.');
            }

            $object[$key] = $value;
        }

        return $object;
    }

    private function preflight(Request $request, ?string $origin): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $allowed = false;

        try {
            $allowed = $origin !== null && $this->originAllowed($origin);
        } catch (Throwable) {
            $allowed = false;
        }

        $this->headers($response, $allowed ? $origin : null);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }

    /** @param array<string, array{code: string, message: string}> $fieldErrors */
    private function failure(string $code, string $message, ?string $origin, int $status = Response::HTTP_UNPROCESSABLE_ENTITY, ?string $requestId = null, array $fieldErrors = [], ?int $retryAfterSeconds = null): JsonResponse
    {
        $fields = [];

        foreach ($fieldErrors as $field => $error) {
            if (count($fields) >= 20) {
                break;
            }

            $fields[] = new SubmissionFieldError($field, $error['code'], $error['message']);
        }

        $response = new JsonResponse(
            new SubmissionErrorResponse($code, $message, $requestId ?? $this->identifierGenerator->uuidV7($this->clock->now()), $fields)->toArray(),
            $status,
        );
        $this->headers($response, $origin);

        if ($retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) max(1, min($retryAfterSeconds, 86400)));
        }

        return $response;
    }

    private function submissionStatus(string $code): int
    {
        return match ($code) {
            'request_too_large' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'configuration_stale', 'attempt_conflict', 'attempt_expired' => Response::HTTP_CONFLICT,
            'field_validation_failed', 'submission_shape_invalid', 'captcha_required', 'captcha_invalid' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'rate_limited' => Response::HTTP_TOO_MANY_REQUESTS,
            'captcha_unavailable', 'submission_unavailable', 'storage_unavailable' => Response::HTTP_SERVICE_UNAVAILABLE,
            'internal_error' => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_BAD_REQUEST,
        };
    }

    private function activationStatus(string $code): int
    {
        return match ($code) {
            'qualification_not_authorized', 'qualification_expired', 'qualification_replayed', 'qualification_form_not_found' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'qualification_payload_too_large' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'qualification_unavailable', 'database_unavailable', 'installation_required' => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_BAD_REQUEST,
        };
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

    private function headers(Response $response, ?string $origin): void
    {
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
                // Never expose an origin when protected settings cannot be read.
            }
        }
    }
}
