<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

use DateTimeZone;
use Formvex\Spoke\Admin\AuthenticationRequestResolver;
use Formvex\Spoke\Admin\SettingsRequestResolver;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Application\Delivery\DeliveryControlService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Application\InstallationSettings\SmtpDiagnosticsService;
use Formvex\Spoke\Application\Overview\AdministratorOverviewService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticView;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use Formvex\Spoke\Domain\Retention\Contract\RetentionRepository;
use Formvex\Spoke\Domain\Scheduler\Contract\SchedulerHealthRepository;
use Formvex\Spoke\Domain\Scheduler\SchedulerHealth;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final class PortalShellController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly AuthenticationRequestResolver $requestResolver,
        private readonly SettingsRequestResolver $settingsRequestResolver,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly InstallationSettingsService $installationSettingsService,
        private readonly SmtpDiagnosticsService $smtpDiagnosticsService,
        private readonly AdministratorOverviewService $overviewService,
        private readonly SpokeStorageResolver $storageResolver,
        private readonly RetentionRepository $retentionRepository,
        private readonly SchedulerHealthRepository $schedulerHealthRepository,
        private readonly Clock $clock,
        private readonly BackupService $backupService,
        private readonly RecoveryHoldStore $recoveryHoldStore,
        private readonly DeliveryControlService $deliveryControlService,
    ) {
    }

    #[Route('/formvex', name: 'spoke_admin_home', methods: ['GET'])]
    public function overview(Request $request): Response
    {
        return $this->renderDestination($request, 'overview');
    }

    #[Route('/formvex/diagnostics', name: 'spoke_admin_diagnostics', methods: ['GET'])]
    public function diagnostics(Request $request): Response
    {
        return $this->renderDestination($request, 'diagnostics');
    }

    #[Route('/formvex/diagnostics/smtp-test', name: 'spoke_admin_diagnostics_smtp_test', methods: ['POST'])]
    public function smtpTest(Request $request): Response
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
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'test_recipient']);
            if (!$this->administratorService->csrfTokenMatches($session, $payload['_token'] ?? '')) {
                throw new AdministratorFailure('csrf_invalid');
            }

            $state = $this->installationSettingsService->sendSmtpTest(
                $this->runtimeConfiguration->applicationRoot,
                $payload['test_recipient'] ?? '',
            );

            return $this->renderDestination(
                $request,
                'diagnostics',
                $state->summary,
                $state->status === SmtpTestStatus::PASSED ? 'success' : ($state->status === SmtpTestStatus::UNCERTAIN ? 'warning' : 'danger'),
            );
        } catch (AdministratorFailure $failure) {
            return $this->renderDestination($request, 'diagnostics', $this->administratorMessage($failure), 'danger');
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderDestination($request, 'diagnostics', $failure->getMessage(), 'danger', $failure->fieldErrors['test_recipient'] ?? null);
        }
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

    private function renderDestination(
        Request $request,
        string $destination,
        ?string $actionMessage = null,
        string $actionVariant = 'information',
        ?string $smtpTestFieldError = null,
    ): Response {
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
        $settingsSnapshot = null;
        $overview = null;
        $paths = null;
        $retentionStatus = null;
        $schedulerHealth = null;
        $backupArchives = [];
        $backupError = null;
        $recoveryHold = null;
        $deliveryControl = null;
        $smtpDiagnostics = null;
        if ($destination === 'overview') {
            $overview = $this->overviewService->summary($this->runtimeConfiguration->applicationRoot);
            try {
                $deliveryControl = $this->deliveryControlService->status($this->runtimeConfiguration->applicationRoot);
            } catch (Throwable) {
                // Overview keeps an explicit status projection without hiding the rest of the page when this source is unavailable.
            }
            try {
                $settingsSnapshot = $this->installationSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
            } catch (Throwable) {
                // The Overview service renders an explicit unavailable state for this source.
            }
        } elseif ($destination === 'diagnostics') {
            try {
                $settingsSnapshot = $this->installationSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
                $smtpDiagnostics = $this->smtpDiagnosticsService->fromSnapshot($settingsSnapshot);
            } catch (Throwable) {
                $smtpDiagnostics = SmtpDiagnosticView::unavailable();
            }
            try {
                $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
                $retentionStatus = $this->retentionRepository->status($paths);
                $schedulerHealth = $this->schedulerHealthRepository->status($paths, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
            } catch (Throwable) {
                $schedulerHealth = new SchedulerHealth([], 'unavailable', 'danger', 'The scheduler health state could not be read safely. Check the private installation storage, then refresh Diagnostics.');
            }
        } else {
            $settingsSnapshot = $this->installationSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            $retentionStatus = $this->retentionRepository->status($paths);
            $schedulerHealth = $this->schedulerHealthRepository->status($paths, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
        }
        if ($destination === 'maintenance' && $paths !== null) {
            try {
                $backupArchives = $this->backupService->list($this->runtimeConfiguration->applicationRoot);
                $recoveryHold = $this->recoveryHoldStore->current($paths);
            } catch (Throwable) {
                $backupError = 'Backup inventory is unavailable until the backup migration and private storage are ready.';
            }
        }
        $backupPagination = PaginationView::fromRequest($request, $backupArchives, 'backup_page', 'backup_page_size');
        $notice = $request->query->getString('notice');
        $error = $request->query->getString('error');
        $response = $this->render('administration/portal.html.twig', [
            'csrfToken' => $csrfToken,
            'currentRoute' => $route,
            'destination' => $destination,
            'pageTitle' => $definition['label'],
            'pageDescription' => $definition['description'],
            'theme' => $theme,
            'sidebarState' => $sidebarState,
            'websiteName' => $settingsSnapshot?->settings->websiteDisplayName ?? 'Website',
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'navGroups' => PortalNavigation::groups(),
            'isOverview' => $destination === 'overview',
            'overview' => $overview,
            'retentionStatus' => $retentionStatus,
            'retentionSettings' => $settingsSnapshot?->settings,
            'schedulerHealth' => $schedulerHealth,
            'deliverySchedulerJob' => $schedulerHealth?->job('delivery'),
            'retentionSchedulerJob' => $schedulerHealth?->job('retention'),
            'backupPagination' => $backupPagination,
            'backupError' => $backupError,
            'recoveryHold' => $recoveryHold,
            'deliveryControl' => $deliveryControl,
            'smtpDiagnostics' => $smtpDiagnostics ?? SmtpDiagnosticView::unavailable(),
            'smtpTestFieldError' => $smtpTestFieldError,
            'notice' => $actionMessage ?? ($notice !== '' ? $notice : ($error !== '' ? $error : null)),
            'noticeVariant' => $actionMessage !== null ? $actionVariant : ($error !== '' ? 'danger' : 'information'),
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

    private function administratorMessage(AdministratorFailure $failure): string
    {
        return match ($failure->failureCode) {
            'csrf_invalid' => 'The SMTP test could not be verified. Reload Diagnostics and submit the test again.',
            'request_malformed' => 'The SMTP test request contained an unsupported or malformed field. Reload Diagnostics and submit the visible fields again.',
            default => 'The SMTP test request could not be verified. Reload Diagnostics and try again.',
        };
    }
}
