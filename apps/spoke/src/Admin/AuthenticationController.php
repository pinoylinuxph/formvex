<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Formvex\Spoke\Infrastructure\Security\LoginCsrfTokenManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthenticationController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    private const LOGIN_CSRF_COOKIE = 'formvex_login_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly AuthenticationRequestResolver $requestResolver,
        private readonly LoginCsrfTokenManager $loginCsrfTokenManager,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/login', name: 'spoke_admin_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (!$request->isSecure()) {
            return new Response('Secure HTTPS is required for administrator authentication.', Response::HTTP_BAD_REQUEST);
        }

        $loginCsrfToken = $this->loginCsrfTokenManager->issue();

        if ($request->isMethod('POST')) {
            try {
                $loginRequest = $this->requestResolver->login($request);
                $cookieToken = $request->cookies->get(self::LOGIN_CSRF_COOKIE);

                if (!is_string($cookieToken) || !hash_equals($cookieToken, $loginRequest->csrfToken) || !$this->loginCsrfTokenManager->isValid($loginRequest->csrfToken)) {
                    throw new AdministratorFailure('csrf_invalid');
                }

                $remoteAddress = $request->server->get('REMOTE_ADDR');
                $clientAddress = is_string($remoteAddress) && $remoteAddress !== '' ? $remoteAddress : 'unknown';
                $session = $this->administratorService->authenticate(
                    $this->runtimeConfiguration->applicationRoot,
                    $loginRequest->loginIdentifier,
                    $loginRequest->password,
                    $clientAddress,
                );
                $response = $this->redirectToRoute(
                    $session->mustChangePassword ? 'spoke_admin_password_change' : 'spoke_admin_home',
                );
                $this->setAuthenticationCookies($response, $session->sessionId, $session->csrfToken);
                $response->headers->clearCookie(self::LOGIN_CSRF_COOKIE, '/formvex');

                return $response;
            } catch (AdministratorFailure $failure) {
                return $this->loginFailureResponse($request, $loginCsrfToken, $failure);
            }
        }

        $response = $this->render('administration/login.html.twig', [
            'csrfToken' => $loginCsrfToken,
            'error' => null,
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
        ]);
        $response->headers->setCookie($this->cookie(self::LOGIN_CSRF_COOKIE, $loginCsrfToken));

        return $response;
    }

    #[Route('/formvex/password/change', name: 'spoke_admin_password_change', methods: ['GET', 'POST'])]
    public function passwordChange(Request $request): Response
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId);

        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        $csrfToken = $this->csrfToken($request, $sessionId);

        if ($request->isMethod('POST')) {
            try {
                $changeRequest = $this->requestResolver->passwordChange($request);

                if ($changeRequest->newPassword !== $changeRequest->confirmation) {
                    throw new AdministratorFailure('password_confirmation_mismatch');
                }

                $newSession = $this->administratorService->changePassword(
                    $this->runtimeConfiguration->applicationRoot,
                    $sessionId,
                    $changeRequest->csrfToken,
                    $changeRequest->newPassword,
                );
                $response = $this->redirectToRoute('spoke_admin_home');
                $this->setAuthenticationCookies($response, $newSession->sessionId, $newSession->csrfToken);

                return $response;
            } catch (AdministratorFailure $failure) {
                $failureToken = $failure->failureCode === 'csrf_invalid'
                    ? $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId)
                    : $csrfToken;

                return $this->renderPasswordChangeFailure($failure, $failureToken, $request);
            } catch (InvalidArgumentException) {
                return $this->renderPasswordChangeFailure(new AdministratorFailure('password_invalid'), $csrfToken, $request);
            }
        }

        $response = $this->render('administration/password_change.html.twig', [
            'csrfToken' => $csrfToken,
            'error' => null,
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
        ]);

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie($this->cookie(self::CSRF_COOKIE, $csrfToken));
        }

        return $response;
    }

    #[Route('/formvex/logout', name: 'spoke_admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        $sessionId = $this->sessionId($request);

        if ($sessionId !== null) {
            try {
                $logoutRequest = $this->requestResolver->csrf($request);
                $this->administratorService->logout(
                    $this->runtimeConfiguration->applicationRoot,
                    $sessionId,
                    $logoutRequest,
                );
            } catch (AdministratorFailure $failure) {
                if ($failure->failureCode === 'csrf_invalid') {
                    return new Response('The security token is invalid.', Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $response = $this->redirectToRoute('spoke_admin_login');
        $response->headers->clearCookie(self::SESSION_COOKIE, '/formvex');
        $response->headers->clearCookie(self::CSRF_COOKIE, '/formvex');

        return $response;
    }

    private function loginFailureResponse(Request $request, string $csrfToken, AdministratorFailure $failure): Response
    {
        $status = match ($failure->failureCode) {
            'invalid_credentials' => Response::HTTP_UNAUTHORIZED,
            'login_rate_limited' => Response::HTTP_TOO_MANY_REQUESTS,
            'csrf_invalid', 'request_malformed' => Response::HTTP_BAD_REQUEST,
            default => Response::HTTP_SERVICE_UNAVAILABLE,
        };
        $response = $this->render('administration/login.html.twig', [
            'csrfToken' => $csrfToken,
            'error' => match ($failure->failureCode) {
                'invalid_credentials' => 'The administrator credentials are not valid.',
                'login_rate_limited' => 'Too many failed attempts. Try again later.',
                'csrf_invalid', 'request_malformed' => 'The security form is invalid. Please try again.',
                default => 'Administrator authentication is temporarily unavailable.',
            },
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
        ], new Response('', $status));
        $response->headers->setCookie($this->cookie(self::LOGIN_CSRF_COOKIE, $csrfToken));

        if ($failure->retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $failure->retryAfterSeconds);
        }

        return $response;
    }

    private function renderPasswordChangeFailure(AdministratorFailure $failure, string $csrfToken, Request $request): Response
    {
        $response = $this->render('administration/password_change.html.twig', [
            'csrfToken' => $csrfToken,
            'error' => match ($failure->failureCode) {
                'password_confirmation_mismatch' => 'The password confirmation does not match.',
                'password_invalid' => 'The password must contain at least 12 characters and no more than 72 UTF-8 bytes.',
                'csrf_invalid' => 'The security token is invalid. Please try again.',
                default => 'The password could not be changed safely.',
            },
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
        ], new Response('', $failure->failureCode === 'csrf_invalid' ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY));

        return $response;
    }

    private function csrfToken(Request $request, string $sessionId): string
    {
        $token = $request->cookies->get(self::CSRF_COOKIE);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId);
    }

    private function sessionId(Request $request): ?string
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function setAuthenticationCookies(Response $response, string $sessionId, string $csrfToken): void
    {
        $response->headers->setCookie($this->cookie(self::SESSION_COOKIE, $sessionId));
        $response->headers->setCookie($this->cookie(self::CSRF_COOKIE, $csrfToken));
    }

    private function cookie(string $name, string $value): Cookie
    {
        return Cookie::create(
            $name,
            $value,
            0,
            '/formvex',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
