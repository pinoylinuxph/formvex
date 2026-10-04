<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

use DateTimeZone;
use Formvex\Spoke\Admin\AuthenticationRequestResolver;
use Formvex\Spoke\Admin\SettingsRequestResolver;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Application\Backup\ScheduledBackupService;
use Formvex\Spoke\Application\Delivery\DeliveryControlService;
use Formvex\Spoke\Application\Diagnostics\DiagnosticReportService;
use Formvex\Spoke\Application\FormChangeObservation\FormChangeObservationService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Application\InstallationSettings\SmtpDiagnosticsService;
use Formvex\Spoke\Application\Overview\AdministratorOverviewService;
use Formvex\Spoke\Application\Release\ReleaseCheckService;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Backup\ScheduledBackupFrequency;
use Formvex\Spoke\Domain\Backup\ScheduledBackupSettings;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReport;
use Formvex\Spoke\Domain\Diagnostics\DiagnosticReportFailure;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\SmtpDiagnosticView;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use Formvex\Spoke\Domain\Release\ReleaseCheckFailure;
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
        private readonly ScheduledBackupService $scheduledBackupService,
        private readonly RecoveryHoldStore $recoveryHoldStore,
        private readonly DeliveryControlService $deliveryControlService,
        private readonly FormChangeObservationService $formChangeObservationService,
        private readonly ReleaseCheckService $releaseCheckService,
        private readonly DiagnosticReportService $diagnosticReportService,
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

    #[Route('/formvex/diagnostics/reports', name: 'spoke_admin_diagnostic_report_generate', methods: ['POST'])]
    public function generateDiagnosticReport(Request $request): Response
    {
        $session = $this->authenticatedSession($request);
        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token']);
            if (!$this->administratorService->csrfTokenMatches($session, $payload['_token'] ?? '')) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $generation = $this->diagnosticReportService->generate($this->runtimeConfiguration->applicationRoot, 'admin');

            return $this->redirectToRoute('spoke_admin_diagnostics', [
                'notice' => $generation->created
                    ? 'A redacted diagnostic report was generated. It is available for 15 minutes.'
                    : 'A recent redacted diagnostic report is already available. No new report was generated during the cooldown period.',
            ]);
        } catch (AdministratorFailure $failure) {
            return $this->redirectToRoute('spoke_admin_diagnostics', ['error' => $this->diagnosticReportMessage($failure->failureCode)]);
        } catch (DiagnosticReportFailure $failure) {
            return $this->redirectToRoute('spoke_admin_diagnostics', ['error' => $failure->getMessage()]);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_diagnostics', ['error' => 'The redacted diagnostic report could not be generated safely. No existing report was changed.']);
        }
    }

    #[Route('/formvex/diagnostics/reports/{reportId}', name: 'spoke_admin_diagnostic_report_preview', methods: ['GET'])]
    public function previewDiagnosticReport(Request $request, string $reportId): Response
    {
        $session = $this->authenticatedSession($request);
        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $report = $this->diagnosticReportService->read($this->runtimeConfiguration->applicationRoot, $reportId, 'admin', 'previewed');

            return $this->renderDestination($request, 'diagnostics', null, 'information', null, $report);
        } catch (DiagnosticReportFailure) {
            return $this->redirectToRoute('spoke_admin_diagnostics', ['error' => 'The diagnostic report is no longer available. Generate a new report from Diagnostics.']);
        }
    }

    #[Route('/formvex/diagnostics/reports/{reportId}/download', name: 'spoke_admin_diagnostic_report_download', methods: ['GET'])]
    public function downloadDiagnosticReport(Request $request, string $reportId): Response
    {
        $session = $this->authenticatedSession($request);
        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $format = $request->query->getString('format');
        if (!in_array($format, ['text', 'json'], true)) {
            return new Response('The report format is not available.', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        try {
            $report = $this->diagnosticReportService->read($this->runtimeConfiguration->applicationRoot, $reportId, 'admin', 'downloaded');
            $extension = $format === 'json' ? 'json' : 'txt';
            $content = $format === 'json' ? $report->toJson() : $this->diagnosticReportService->plainText($report);
            $date = $report->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

            return new Response($content, Response::HTTP_OK, [
                'Content-Type' => $format === 'json' ? 'application/json; charset=UTF-8' : 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="diagnostic-report-' . $date . '.' . $extension . '"',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (DiagnosticReportFailure) {
            return new Response('The diagnostic report is not available.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
        }
    }

    #[Route('/formvex/maintenance', name: 'spoke_admin_maintenance', methods: ['GET'])]
    public function maintenance(Request $request): Response
    {
        return $this->renderDestination($request, 'maintenance');
    }

    #[Route('/formvex/maintenance/scheduled-backup', name: 'spoke_admin_scheduled_backup_settings', methods: ['POST'])]
    public function scheduledBackupSettings(Request $request): Response
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
            $payload = $request->request->all();
            $allowed = ['_token', 'enabled', 'frequency', 'weekday', 'hour', 'minute', 'retention_count'];
            if (array_diff(array_keys($payload), $allowed) !== []) {
                throw new AdministratorFailure('request_malformed');
            }
            if (!$this->administratorService->csrfTokenMatches($session, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $frequency = ScheduledBackupFrequency::tryFrom($request->request->getString('frequency'));
            $weekday = filter_var($request->request->getString('weekday'), FILTER_VALIDATE_INT);
            $hour = filter_var($request->request->getString('hour'), FILTER_VALIDATE_INT);
            $minute = filter_var($request->request->getString('minute'), FILTER_VALIDATE_INT);
            $retentionCount = filter_var($request->request->getString('retention_count'), FILTER_VALIDATE_INT);
            if ($frequency === null || $weekday === false || $hour === false || $minute === false || $retentionCount === false || $weekday < 0 || $weekday > 6 || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $retentionCount < 1 || $retentionCount > 12) {
                throw new AdministratorFailure('scheduled_backup_settings_invalid');
            }
            $current = $this->scheduledBackupService->settings($this->runtimeConfiguration->applicationRoot);
            $settings = new ScheduledBackupSettings(
                $request->request->getString('enabled') === '1',
                $frequency,
                $weekday,
                $hour,
                $minute,
                $retentionCount,
                $current->lastDuePeriod,
                $current->lastAttemptAt,
                $current->lastSuccessAt,
                $current->lastStatus,
                $current->lastErrorCode,
                $current->lastCandidateId,
                $current->lastCleanupAt,
                $current->updatedAt,
            );
            $this->scheduledBackupService->saveSettings($this->runtimeConfiguration->applicationRoot, $settings);

            return $this->redirectToRoute('spoke_admin_maintenance', ['notice' => 'Scheduled backup settings were saved. The hosting scheduler will create the next due verified copy.']);
        } catch (AdministratorFailure $failure) {
            $message = $failure->failureCode === 'scheduled_backup_settings_invalid' ? 'Enter a valid schedule and a retention count from 1 to 12.' : 'The scheduled-backup security request could not be verified. Refresh Maintenance and try again.';

            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => $message]);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'Scheduled backup settings could not be saved. No existing archive was changed.']);
        }
    }

    #[Route('/formvex/maintenance/release-check', name: 'spoke_admin_release_check_settings', methods: ['POST'])]
    public function releaseCheckSettings(Request $request): Response
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId);
        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $request->request->all();
            if (array_diff(array_keys($payload), ['_token', 'enabled']) !== []) {
                throw new AdministratorFailure('request_malformed');
            }
            if (!$this->administratorService->csrfTokenMatches($session, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $enabled = $request->request->getString('enabled') === '1';
            $this->releaseCheckService->saveEnabled($this->runtimeConfiguration->applicationRoot, $enabled);

            return $this->redirectToRoute('spoke_admin_maintenance', ['notice' => $enabled
                ? 'Release checks are enabled. The hosting scheduler should run the daily release-check command.'
                : 'Release checks are disabled. No release metadata request will be made.']);
        } catch (AdministratorFailure $failure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => $failure->failureCode === 'csrf_invalid'
                ? 'The release-check settings request could not be verified. Refresh Maintenance and try again.'
                : 'The release-check settings request was malformed.']);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'Release-check settings could not be saved. The previous setting remains active.']);
        }
    }

    #[Route('/formvex/maintenance/release-check/acknowledge', name: 'spoke_admin_release_check_acknowledge', methods: ['POST'])]
    public function acknowledgeRelease(Request $request): Response
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId);
        if ($session === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($session->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $request->request->all();
            if (array_diff(array_keys($payload), ['_token', 'release_version']) !== []) {
                throw new AdministratorFailure('request_malformed');
            }
            if (!$this->administratorService->csrfTokenMatches($session, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $this->releaseCheckService->acknowledge($this->runtimeConfiguration->applicationRoot, $request->request->getString('release_version'), 'admin');

            return $this->redirectToRoute('spoke_admin_maintenance', ['notice' => 'The release notice was acknowledged for this exact release.']);
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The release acknowledgement could not be verified. Refresh Maintenance and try again.']);
        } catch (ReleaseCheckFailure $failure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => $failure->getMessage()]);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The release notice could not be acknowledged. Refresh Maintenance and review the current result.']);
        }
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
        ?DiagnosticReport $diagnosticReport = null,
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
        $scheduledBackupSettings = null;
        $scheduledBackupArchives = [];
        $scheduledBackupError = null;
        $recoveryHold = null;
        $deliveryControl = null;
        $formChangeWarningCount = 0;
        $smtpDiagnostics = null;
        $releaseCheckSettings = null;
        $releaseCheckState = null;
        $releaseCheckStatus = null;
        $releaseCheckNoticeVisible = false;
        $diagnosticReportMetadata = null;
        $diagnosticReportError = null;
        if ($destination === 'overview' || $destination === 'maintenance') {
            try {
                $releaseCheckSettings = $this->releaseCheckService->settings($this->runtimeConfiguration->applicationRoot);
                $releaseCheckState = $this->releaseCheckService->state($this->runtimeConfiguration->applicationRoot);
                $releaseCheckStatus = $releaseCheckState->displayStatus($this->clock->now());
                $releaseCheckNoticeVisible = $releaseCheckState->noticeVisible();
            } catch (Throwable) {
                // Release-check state remains explicitly unavailable until the Unit 25 migration is ready.
            }
        }
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
            try {
                $formChangeWarningCount = $this->formChangeObservationService->countOpen($this->runtimeConfiguration->applicationRoot);
            } catch (Throwable) {
                // Overview keeps the rest of its status projection available while warnings are unavailable.
            }
            try {
                $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
                $schedulerHealth = $this->schedulerHealthRepository->status($paths, $this->clock->now()->setTimezone(new DateTimeZone('UTC')));
            } catch (Throwable) {
                // Overview keeps scheduler state unavailable rather than claiming a healthy scheduled task.
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
            try {
                $diagnosticReportMetadata = $this->diagnosticReportService->latest($this->runtimeConfiguration->applicationRoot);
            } catch (Throwable) {
                $diagnosticReportError = 'Diagnostic report storage is unavailable. No report was generated.';
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
            try {
                $scheduledBackupSettings = $this->scheduledBackupService->settings($this->runtimeConfiguration->applicationRoot);
                $scheduledBackupArchives = $this->scheduledBackupService->list($this->runtimeConfiguration->applicationRoot);
            } catch (Throwable) {
                $scheduledBackupError = 'Scheduled backup settings and inventory are unavailable until the current database migration and private storage are ready.';
            }
        }
        $backupPagination = PaginationView::fromRequest($request, $backupArchives, 'backup_page', 'backup_page_size');
        $scheduledBackupPagination = PaginationView::fromRequest($request, $scheduledBackupArchives, 'scheduled_backup_page', 'scheduled_backup_page_size');
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
            'scheduledBackupSchedulerJob' => $schedulerHealth?->job('scheduled_backup'),
            'backupPagination' => $backupPagination,
            'backupError' => $backupError,
            'scheduledBackupSettings' => $scheduledBackupSettings,
            'scheduledBackupArchives' => $scheduledBackupArchives,
            'scheduledBackupPagination' => $scheduledBackupPagination,
            'scheduledBackupError' => $scheduledBackupError,
            'recoveryHold' => $recoveryHold,
            'deliveryControl' => $deliveryControl,
            'formChangeWarningCount' => $formChangeWarningCount,
            'smtpDiagnostics' => $smtpDiagnostics ?? SmtpDiagnosticView::unavailable(),
            'smtpTestFieldError' => $smtpTestFieldError,
            'releaseCheckSettings' => $releaseCheckSettings,
            'releaseCheckState' => $releaseCheckState,
            'releaseCheckStatus' => $releaseCheckStatus,
            'releaseCheckNoticeVisible' => $releaseCheckNoticeVisible,
            'diagnosticReport' => $diagnosticReport,
            'diagnosticReportMetadata' => $diagnosticReportMetadata,
            'diagnosticReportError' => $diagnosticReportError,
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

    private function authenticatedSession(Request $request): ?SessionRecord
    {
        $sessionId = $this->sessionId($request);

        return $sessionId === null ? null : $this->administratorService->session(
            $this->runtimeConfiguration->applicationRoot,
            $sessionId,
        );
    }

    private function diagnosticReportMessage(string $failureCode): string
    {
        return match ($failureCode) {
            'csrf_invalid' => 'The diagnostic report request could not be verified. Refresh Diagnostics and try again.',
            'request_malformed' => 'The diagnostic report request was malformed. Refresh Diagnostics and try again.',
            default => 'The diagnostic report could not be generated safely. No existing report was changed.',
        };
    }
}
