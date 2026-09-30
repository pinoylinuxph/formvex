<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\Submission;

use Formvex\Contracts\V1\Submission\SubmissionAcceptedResponse;
use Formvex\Contracts\V1\Submission\SubmissionErrorResponse;
use Formvex\Contracts\V1\Submission\SubmissionFieldError;
use Formvex\Spoke\Application\Abuse\SubmissionAbuseService;
use Formvex\Spoke\Application\Submission\SubmissionService;
use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Domain\Submission\SubmissionAccepted;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class SubmissionController extends AbstractController
{
    public function __construct(
        private readonly SubmissionService $submissionService,
        private readonly SubmissionRequestResolver $requestResolver,
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

    #[Route('/formvex/api/v1/forms/{publicFormId}/submissions', name: 'spoke_api_v1_submission_accept', methods: ['POST', 'OPTIONS'])]
    public function submit(Request $request, string $publicFormId): Response
    {
        $origin = $request->headers->get('Origin');
        $requestId = $this->identifierGenerator->uuidV7($this->clock->now());

        if ($request->isMethod('OPTIONS')) {
            return $this->preflight($request, $origin);
        }

        try {
            if (!$this->validPublicId($publicFormId)) {
                return $this->failure('form_unavailable', 'Formvex could not find an approved active form for this request.', $requestId, $origin, Response::HTTP_NOT_FOUND);
            }

            if (!$request->isSecure() || !$this->publicHostAllowed($request->getHost()) || ($origin !== null && !$this->originAllowed($origin))) {
                return $this->failure('form_unavailable', 'Formvex could not find an approved active form for this request.', $requestId, $origin, Response::HTTP_NOT_FOUND);
            }

            $clientIp = $this->clientIpResolver->resolve($request, $this->abuseSettingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot))->trustedProxyCidrs);
            $this->abuseService->assertFloodAllowed($this->runtimeConfiguration->applicationRoot, $clientIp);

            $contentType = $request->headers->get('Content-Type') ?? '';

            if (!str_starts_with(strtolower($contentType), 'application/json')) {
                return $this->failure('request_invalid', 'The submission request must use the application/json content type.', $requestId, $origin, Response::HTTP_BAD_REQUEST);
            }

            $contentLength = $request->headers->get('Content-Length');

            if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > SubmissionRequestResolver::MAX_BODY_BYTES) {
                return $this->failure('request_too_large', 'The submission request exceeds the 128 KiB limit. Shorten the message and try again.', $requestId, $origin, Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
            }

            $submissionRequest = $this->requestResolver->resolve($request->getContent());
            $accepted = $this->submissionService->accept($this->runtimeConfiguration->applicationRoot, $publicFormId, $submissionRequest, null, $clientIp);
            $response = new JsonResponse(
                new SubmissionAcceptedResponse($accepted->receiptId, SubmissionAccepted::ACKNOWLEDGEMENT)->toArray(),
                Response::HTTP_ACCEPTED,
            );
            $this->headers($response, $origin);

            return $response;
        } catch (SubmissionFailure $failure) {
            return $this->failure($failure->failureCode, $failure->getMessage(), $requestId, $origin, $this->status($failure->failureCode), $failure->fieldErrors, $failure->retryAfterSeconds);
        } catch (InstallationSettingsFailure) {
            return $this->failure('submission_unavailable', 'Formvex could not safely apply its abuse controls. Your message was not accepted. Please try again later.', $requestId, $origin, Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (Throwable) {
            return $this->failure('internal_error', 'Formvex could not safely process this submission. Your message was not accepted. Try again later.', $requestId, $origin, Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function preflight(Request $request, ?string $origin): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $allowed = false;

        try {
            $allowed = $origin !== null && $this->publicHostAllowed($request->getHost()) && $this->originAllowed($origin);
        } catch (Throwable) {
            $allowed = false;
        }

        $this->headers($response, $allowed ? $origin : null);

        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');

        return $response;
    }

    /** @param array<string, array{code: string, message: string}> $fieldErrors */
    private function failure(string $code, string $message, string $requestId, ?string $origin, int $status, array $fieldErrors = [], ?int $retryAfterSeconds = null): JsonResponse
    {
        $fields = [];

        foreach ($fieldErrors as $field => $error) {
            if (count($fields) >= 20) {
                break;
            }

            $fields[] = new SubmissionFieldError($field, $error['code'], $error['message']);
        }

        $response = new JsonResponse(
            new SubmissionErrorResponse($code, $message, $requestId, $fields)->toArray(),
            $status,
        );
        $this->headers($response, $origin);

        if ($retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) max(1, min($retryAfterSeconds, 86400)));
        }

        return $response;
    }

    private function status(string $code): int
    {
        return match ($code) {
            'form_unavailable' => Response::HTTP_NOT_FOUND,
            'request_too_large' => Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            'configuration_stale', 'attempt_conflict', 'attempt_expired' => Response::HTTP_CONFLICT,
            'field_validation_failed', 'submission_shape_invalid' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'rate_limited' => Response::HTTP_TOO_MANY_REQUESTS,
            'captcha_required', 'captcha_invalid' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'captcha_unavailable', 'submission_unavailable', 'storage_unavailable' => Response::HTTP_SERVICE_UNAVAILABLE,
            'internal_error' => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_BAD_REQUEST,
        };
    }

    private function validPublicId(string $publicFormId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $publicFormId) === 1;
    }

    private function publicHostAllowed(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $settings = $this->settingsStore->get($this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot));

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
                // A storage failure must not expose an origin through an error response.
            }
        }
    }
}
