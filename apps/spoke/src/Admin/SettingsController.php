<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Abuse\SubmissionAbuseSettingsService;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\Branding\BrandingService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Application\Storage\StorageSettingsService;
use Formvex\Spoke\Domain\Abuse\AbuseSettingsSnapshot;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Branding\BrandingUpload;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    /** @var list<string> */
    private const SETTINGS_TABS = ['website', 'email', 'access', 'protection', 'retention', 'storage'];

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly SettingsRequestResolver $settingsRequestResolver,
        private readonly BrandingService $brandingService,
        private readonly InstallationSettingsService $settingsService,
        private readonly StorageSettingsService $storageSettingsService,
        private readonly SubmissionAbuseSettingsService $abuseSettingsService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/settings', name: 'spoke_admin_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context['session']->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        return $this->renderSettings($request, $this->sessionId($request) ?? '', $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot), activeTab: $this->settingsTab($request->query->get('tab'), 'website'));
    }

    #[Route('/formvex/settings/identity', name: 'spoke_admin_settings_identity_update', methods: ['POST'])]
    public function saveIdentity(Request $request): Response
    {
        return $this->save($request, 'identity', [
            'website_display_name',
            'bare_domain',
            'www_alias',
            'operational_alert_email',
        ]);
    }

    #[Route('/formvex/settings/branding', name: 'spoke_admin_settings_branding_update', methods: ['POST'])]
    public function saveBranding(Request $request): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context['session']->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = $this->formData($snapshot);
        $activeTab = $this->settingsTab($request->query->get('tab'), 'website');

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'brand_name', 'slogan', 'show_slogan', 'remove_logo', 'remove_favicon']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            unset($payload['_token']);
            $formData = array_merge($formData, $payload);
            $result = $this->brandingService->save(
                $this->runtimeConfiguration->applicationRoot,
                $payload,
                $this->brandingUpload($request, 'logo'),
                $this->brandingUpload($request, 'favicon'),
                ($payload['remove_logo'] ?? '0') === '1',
                ($payload['remove_favicon'] ?? '0') === '1',
            );
            $message = $result->cleanupWarning
                ? 'Branding was saved, but an older asset could not be removed. The new branding is active; review storage cleanup in Maintenance.'
                : 'Branding settings were saved successfully.';

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $this->formData($snapshot), $message, $result->cleanupWarning ? 'warning' : 'success', '', [], null, $activeTab);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', '', [], null, $activeTab);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', '', $failure->fieldErrors, null, $activeTab);
        }
    }

    #[Route('/formvex/settings/smtp', name: 'spoke_admin_settings_smtp_update', methods: ['POST'])]
    public function saveSmtp(Request $request): Response
    {
        return $this->save($request, 'smtp', [
            'sender_email',
            'sender_name',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'smtp_timeout_seconds',
            'smtp_attempts_per_minute',
        ]);
    }

    #[Route('/formvex/settings/security', name: 'spoke_admin_settings_security_update', methods: ['POST'])]
    public function saveSecurity(Request $request): Response
    {
        return $this->save($request, 'security', [
            'maximum_failures',
            'window_minutes',
            'cooldown_minutes',
        ]);
    }

    #[Route('/formvex/settings/discovery', name: 'spoke_admin_settings_discovery_update', methods: ['POST'])]
    public function saveDiscovery(Request $request): Response
    {
        return $this->save($request, 'discovery', ['discovery_payload_limit_kib']);
    }

    #[Route('/formvex/settings/retention', name: 'spoke_admin_settings_retention_update', methods: ['POST'])]
    public function saveRetention(Request $request): Response
    {
        return $this->save($request, 'retention', [
            'ordinary_retention_days',
            'uncertain_retention_days',
            'audit_retention_days',
        ]);
    }

    #[Route('/formvex/settings/storage', name: 'spoke_admin_settings_storage_update', methods: ['POST'])]
    public function saveStorage(Request $request): Response
    {
        $context = $this->authenticatedContext($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context['session']->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = $this->formData($snapshot);
        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'storage_allowance_gb', 'storage_normal_warning_percent', 'storage_critical_warning_percent']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            unset($payload['_token']);
            $formData = array_merge($formData, $payload);
            $this->storageSettingsService->save($this->runtimeConfiguration->applicationRoot, $payload);

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, 'Storage settings were saved successfully.', 'success', '', [], null, 'storage');
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', '', [], null, 'storage');
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', '', $failure->fieldErrors, null, 'storage');
        }
    }

    #[Route('/formvex/settings/abuse', name: 'spoke_admin_settings_abuse_update', methods: ['POST'])]
    public function saveAbuse(Request $request): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $abuseSnapshot = $this->abuseSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = $this->formData($snapshot);
        $activeTab = $this->settingsTab($request->query->get('tab'), 'protection');

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'per_form_short_limit', 'per_form_hour_limit', 'installation_hour_limit', 'flood_minute_limit', 'flood_hour_limit', 'trusted_proxy_cidrs', 'turnstile_secret']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            unset($payload['_token']);
            $secret = trim($payload['turnstile_secret'] ?? '');
            unset($payload['turnstile_secret']);
            $abuseSnapshot = $this->abuseSettingsService->save($this->runtimeConfiguration->applicationRoot, $payload);

            if ($secret !== '') {
                $abuseSnapshot = $this->abuseSettingsService->saveTurnstileSecret($this->runtimeConfiguration->applicationRoot, $secret);
            }

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, array_merge($formData, ['turnstile_secret' => '']), 'Abuse-control settings were saved. Existing counters remain active until their configured windows expire.', 'success', '', [], $abuseSnapshot, $activeTab);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', '', [], $abuseSnapshot, $activeTab);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', '', $failure->fieldErrors, $abuseSnapshot, $activeTab);
        }
    }

    #[Route('/formvex/settings/abuse/reset', name: 'spoke_admin_settings_abuse_reset', methods: ['POST'])]
    public function resetAbuse(Request $request): Response
    {
        return $this->abuseAction($request, 'reset');
    }

    #[Route('/formvex/settings/abuse/clear-counters', name: 'spoke_admin_settings_abuse_clear_counters', methods: ['POST'])]
    public function clearAbuseCounters(Request $request): Response
    {
        return $this->abuseAction($request, 'clear');
    }

    #[Route('/formvex/settings/smtp-test', name: 'spoke_admin_settings_smtp_test', methods: ['POST'])]
    public function smtpTest(Request $request): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context['session']->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = $this->formData($snapshot);
        $activeTab = $this->settingsTab($request->query->get('tab'), 'email');

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'test_recipient']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            $recipient = $payload['test_recipient'] ?? '';
            $state = $this->settingsService->sendSmtpTest($this->runtimeConfiguration->applicationRoot, $recipient);
            $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $state->summary, $state->status === SmtpTestStatus::PASSED ? 'success' : 'danger', $recipient, [], null, $activeTab);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', $formData['test_recipient'], [], null, $activeTab);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', $formData['test_recipient'], $failure->fieldErrors, null, $activeTab);
        }
    }

    /** @param list<string> $allowedKeys */
    private function save(Request $request, string $group, array $allowedKeys): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context['session']->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = $this->formData($snapshot);
        $activeTab = $this->settingsTab($request->query->get('tab'), $this->settingsTabForGroup($group));

        try {
            $payload = $this->settingsRequestResolver->payload($request, array_merge(['_token'], $allowedKeys));
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            unset($payload['_token']);
            $formData = array_merge($formData, $payload);
            $snapshot = match ($group) {
                'identity' => $this->settingsService->saveIdentity($this->runtimeConfiguration->applicationRoot, $payload),
                'smtp' => $this->settingsService->saveSmtp($this->runtimeConfiguration->applicationRoot, $payload),
                'security' => $this->settingsService->saveSecurity($this->runtimeConfiguration->applicationRoot, $payload),
                'discovery' => $this->settingsService->saveDiscovery($this->runtimeConfiguration->applicationRoot, $payload),
                'retention' => $this->settingsService->saveRetention($this->runtimeConfiguration->applicationRoot, $payload),
                default => throw new InstallationSettingsFailure('settings_group_invalid', 'Formvex could not identify the settings group being saved.'),
            };

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $this->formData($snapshot), 'The ' . $group . ' settings were saved successfully.', 'success', '', [], null, $activeTab);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', '', [], null, $activeTab);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', $formData['test_recipient'], $failure->fieldErrors, null, $activeTab);
        }
    }

    /** @return array{session: \Formvex\Spoke\Domain\Administration\SessionRecord}|null */
    private function authenticatedContext(Request $request): ?array
    {
        $sessionId = $this->sessionId($request);
        $session = $sessionId === null ? null : $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId);

        return $session === null ? null : ['session' => $session];
    }

    /**
     * @param array<string, string> $formData
     * @param array<string, string> $fieldErrors
     */
    private function renderSettings(
        Request $request,
        string $sessionIdHash,
        SettingsSnapshot $snapshot,
        array $formData = [],
        ?string $message = null,
        string $variant = 'information',
        string $testRecipient = '',
        array $fieldErrors = [],
        ?AbuseSettingsSnapshot $abuseSnapshot = null,
        ?string $activeTab = null,
    ): Response {
        $sessionId = $this->sessionId($request) ?? $sessionIdHash;
        $csrfToken = $this->csrfToken($request, $sessionId);
        $activeTab = $this->settingsTab($activeTab ?? $request->query->get('tab'), 'website');
        $abuseSnapshot ??= $this->abuseSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $storageSnapshot = $this->storageSettingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $formData = array_merge($this->formData($snapshot), [
            'brand_name' => $this->brandingService->snapshot($this->runtimeConfiguration->applicationRoot)->brandName,
            'slogan' => $this->brandingService->snapshot($this->runtimeConfiguration->applicationRoot)->slogan,
            'show_slogan' => $this->brandingService->snapshot($this->runtimeConfiguration->applicationRoot)->sloganVisible ? '1' : '0',
            'per_form_short_limit' => (string) $abuseSnapshot->settings->perFormShortLimit,
            'per_form_hour_limit' => (string) $abuseSnapshot->settings->perFormHourLimit,
            'installation_hour_limit' => (string) $abuseSnapshot->settings->installationHourLimit,
            'flood_minute_limit' => (string) $abuseSnapshot->settings->floodMinuteLimit,
            'flood_hour_limit' => (string) $abuseSnapshot->settings->floodHourLimit,
            'trusted_proxy_cidrs' => implode("\n", $abuseSnapshot->settings->normalizedTrustedProxyCidrs()),
            'turnstile_secret' => '',
            'storage_allowance_gb' => (string) $storageSnapshot->settings->allowanceGigabytes(),
            'storage_normal_warning_percent' => (string) $storageSnapshot->settings->normalWarningPercent,
            'storage_critical_warning_percent' => (string) $storageSnapshot->settings->criticalWarningPercent,
        ], $formData);
        $response = $this->render('administration/settings.html.twig', [
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_settings',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => $snapshot->settings->websiteDisplayName,
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'pageTitle' => 'Settings',
            'pageDescription' => 'Configure this local Formvex installation by operational purpose.',
            'activeTab' => $activeTab,
            'settingsTabs' => [
                ['id' => 'website', 'label' => 'Website', 'description' => 'Identity and domain'],
                ['id' => 'email', 'label' => 'Email delivery', 'description' => 'SMTP and test email'],
                ['id' => 'access', 'label' => 'Administrator access', 'description' => 'Login protection'],
                ['id' => 'protection', 'label' => 'Submission protection', 'description' => 'Discovery, limits, and CAPTCHA'],
                ['id' => 'retention', 'label' => 'Retention', 'description' => 'Submission and audit lifecycle'],
                ['id' => 'storage', 'label' => 'Storage', 'description' => 'Allowance, warnings, and exports'],
            ],
            'settings' => $snapshot,
            'formData' => $formData,
            'testRecipient' => $testRecipient,
            'message' => $message,
            'messageVariant' => $variant,
            'fieldErrors' => $fieldErrors,
            'abuseSettings' => $abuseSnapshot,
            'storageSnapshot' => $storageSnapshot,
        ]);

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie($this->cookie(self::CSRF_COOKIE, $csrfToken));
        }

        return $response;
    }

    /** @return array<string, string> */
    private function formData(SettingsSnapshot $snapshot): array
    {
        $settings = $snapshot->settings;

        return [
            'website_display_name' => $settings->websiteDisplayName,
            'bare_domain' => $settings->bareDomain,
            'www_alias' => $settings->wwwAlias ?? '',
            'operational_alert_email' => $settings->operationalAlertEmail,
            'sender_email' => $settings->senderEmail,
            'sender_name' => $settings->senderName ?? '',
            'smtp_host' => $settings->smtpHost,
            'smtp_port' => (string) $settings->smtpPort,
            'smtp_encryption' => $settings->smtpEncryption->value,
            'smtp_username' => $settings->smtpUsername,
            'smtp_password' => '',
            'smtp_timeout_seconds' => (string) $settings->smtpTimeoutSeconds,
            'smtp_attempts_per_minute' => (string) $settings->smtpAttemptsPerMinute,
            'maximum_failures' => (string) $settings->loginThrottle->maximumFailures,
            'window_minutes' => (string) $settings->loginThrottle->windowMinutes,
            'cooldown_minutes' => (string) $settings->loginThrottle->cooldownMinutes,
            'discovery_payload_limit_kib' => (string) intdiv($settings->discoveryPayloadLimitBytes, 1024),
            'ordinary_retention_days' => (string) $settings->ordinaryRetentionDays,
            'uncertain_retention_days' => (string) $settings->uncertainRetentionDays,
            'audit_retention_days' => (string) $settings->auditRetentionDays,
            'test_recipient' => '',
            'per_form_short_limit' => '5',
            'per_form_hour_limit' => '20',
            'installation_hour_limit' => '30',
            'flood_minute_limit' => '60',
            'flood_hour_limit' => '300',
            'trusted_proxy_cidrs' => '',
            'turnstile_secret' => '',
            'brand_name' => 'Noname',
            'slogan' => '',
            'show_slogan' => '0',
        ];
    }

    private function brandingUpload(Request $request, string $key): ?BrandingUpload
    {
        $file = $request->files->get($key);

        if ($file === null) {
            return null;
        }

        if (!$file instanceof UploadedFile) {
            throw new InstallationSettingsFailure('branding_upload_invalid', 'The ' . $key . ' upload was not received as a valid file. Choose the file again.');
        }

        return new BrandingUpload($file->getPathname(), $file->getClientOriginalName(), $file->getError());
    }

    private function settingsTab(mixed $candidate, string $fallback): string
    {
        return is_string($candidate) && in_array($candidate, self::SETTINGS_TABS, true) ? $candidate : $fallback;
    }

    private function settingsTabForGroup(string $group): string
    {
        return match ($group) {
            'identity' => 'website',
            'smtp' => 'email',
            'security' => 'access',
            'discovery' => 'protection',
            'retention' => 'retention',
            'storage' => 'storage',
            default => 'website',
        };
    }

    private function abuseAction(Request $request, string $action): Response
    {
        $context = $this->authenticatedContext($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);
        $activeTab = $this->settingsTab($request->query->get('tab'), 'protection');

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'confirm_action']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');

            if (($payload['confirm_action'] ?? '') !== '1') {
                throw new InstallationSettingsFailure('abuse_action_confirmation_required', 'Confirm the requested abuse-control action before continuing.');
            }

            $abuseSnapshot = $action === 'reset'
                ? $this->abuseSettingsService->resetDefaults($this->runtimeConfiguration->applicationRoot)
                : $this->abuseSettingsService->clearCounters($this->runtimeConfiguration->applicationRoot);
            $message = $action === 'reset' ? 'Abuse-control settings were restored to their defaults. Active counters were not cleared.' : 'Active abuse counters were cleared. The configured limits remain unchanged.';

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, [], $message, 'success', '', [], $abuseSnapshot, $activeTab);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, [], $this->administratorMessage($failure), 'danger', '', [], null, $activeTab);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, [], $failure->getMessage(), 'danger', '', [], null, $activeTab);
        }
    }

    /** @param \Formvex\Spoke\Domain\Administration\SessionRecord $session */
    private function assertCsrf(\Formvex\Spoke\Domain\Administration\SessionRecord $session, string $token): void
    {
        if (!$this->administratorService->csrfTokenMatches($session, $token)) {
            throw new AdministratorFailure('csrf_invalid');
        }
    }

    private function sessionId(Request $request): ?string
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function csrfToken(Request $request, string $sessionId): string
    {
        $token = $request->cookies->get(self::CSRF_COOKIE);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId);
    }

    private function cookie(string $name, string $value): Cookie
    {
        return Cookie::create($name, $value, 0, '/formvex', null, true, true, false, Cookie::SAMESITE_LAX);
    }

    private function administratorMessage(AdministratorFailure $failure): string
    {
        return match ($failure->failureCode) {
            'csrf_invalid' => 'The settings form could not be verified. Reload the Settings page and submit it again.',
            'request_malformed' => 'The settings request contained an unsupported or malformed field. Reload the page and submit the visible fields again.',
            default => 'Formvex could not verify the settings request. Reload the Settings page and try again.',
        };
    }
}
