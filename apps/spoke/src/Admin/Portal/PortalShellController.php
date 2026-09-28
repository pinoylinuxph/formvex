<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

use Formvex\Spoke\Admin\AuthenticationRequestResolver;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PortalShellController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly AuthenticationRequestResolver $requestResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly InstallationSettingsService $installationSettingsService,
    ) {
    }

    #[Route('/formvex', name: 'spoke_admin_home', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        return $this->renderDestination($request, 'overview');
    }

    #[Route('/formvex/submissions', name: 'spoke_admin_submissions', methods: ['GET'])]
    public function submissions(Request $request): Response
    {
        return $this->renderDestination($request, 'submissions');
    }

    #[Route('/formvex/delivery', name: 'spoke_admin_delivery', methods: ['GET'])]
    public function delivery(Request $request): Response
    {
        return $this->renderDestination($request, 'delivery');
    }

    #[Route('/formvex/diagnostics', name: 'spoke_admin_diagnostics', methods: ['GET'])]
    public function diagnostics(Request $request): Response
    {
        return $this->renderDestination($request, 'diagnostics');
    }

    #[Route('/formvex/maintenance', name: 'spoke_admin_maintenance', methods: ['GET'])]
    public function maintenance(Request $request): Response
    {
        return $this->renderDestination($request, 'maintenance');
    }

    #[Route('/formvex/preferences/theme', name: 'spoke_admin_theme', methods: ['POST'])]
    public function theme(Request $request): Response
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session(
            $this->runtimeConfiguration->applicationRoot,
            $sessionId,
        );

        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $preference = $this->requestResolver->themePreference($request);

            if (!$this->administratorService->csrfTokenMatches($session, $preference->csrfToken)) {
                throw new AdministratorFailure('csrf_invalid');
            }

            if (!in_array($preference->theme, ['light', 'dark'], true)
                || !in_array($preference->returnRoute, PortalNavigation::returnRoutes(), true)) {
                throw new AdministratorFailure('request_malformed');
            }

            $response = $this->redirectToRoute($preference->returnRoute);
            $response->headers->setCookie(PortalPreferences::themeCookie($preference->theme));

            return $response;
        } catch (AdministratorFailure) {
            return new Response('The preference request is invalid.', Response::HTTP_BAD_REQUEST);
        }
    }

    private function renderDestination(Request $request, string $destination): Response
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session(
            $this->runtimeConfiguration->applicationRoot,
            $sessionId,
        );

        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $definition = PortalNavigation::destinations()[$destination];
        $route = $definition['route'];
        $csrfToken = $this->csrfToken($request, $sessionId);
        $theme = PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE));
        $sidebarState = PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE));
        $response = $this->render('administration/portal.html.twig', [
            'csrfToken' => $csrfToken,
            'currentRoute' => $route,
            'destination' => $destination,
            'pageTitle' => $definition['label'],
            'pageDescription' => $definition['description'],
            'theme' => $theme,
            'sidebarState' => $sidebarState,
            'websiteName' => $this->installationSettingsService->snapshot($this->runtimeConfiguration->applicationRoot)->settings->websiteDisplayName,
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'isOverview' => $destination === 'overview',
        ]);

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie($this->cookie(self::CSRF_COOKIE, $csrfToken));
        }

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
