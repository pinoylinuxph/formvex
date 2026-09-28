<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\InstallationSettings\InstallationSettingsService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\InstallationSettings\SettingsSnapshot;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestStatus;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly SettingsRequestResolver $settingsRequestResolver,
        private readonly InstallationSettingsService $settingsService,
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

        return $this->renderSettings($request, $this->sessionId($request) ?? '', $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot));
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

        try {
            $payload = $this->settingsRequestResolver->payload($request, ['_token', 'test_recipient']);
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            $recipient = $payload['test_recipient'] ?? '';
            $state = $this->settingsService->sendSmtpTest($this->runtimeConfiguration->applicationRoot, $recipient);
            $snapshot = $this->settingsService->snapshot($this->runtimeConfiguration->applicationRoot);

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $state->summary, $state->status === SmtpTestStatus::PASSED ? 'success' : 'danger', $recipient);
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger', $formData['test_recipient']);
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', $formData['test_recipient'], $failure->fieldErrors);
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

        try {
            $payload = $this->settingsRequestResolver->payload($request, array_merge(['_token'], $allowedKeys));
            $this->assertCsrf($context['session'], $payload['_token'] ?? '');
            unset($payload['_token']);
            $formData = array_merge($formData, $payload);
            $snapshot = match ($group) {
                'identity' => $this->settingsService->saveIdentity($this->runtimeConfiguration->applicationRoot, $payload),
                'smtp' => $this->settingsService->saveSmtp($this->runtimeConfiguration->applicationRoot, $payload),
                'security' => $this->settingsService->saveSecurity($this->runtimeConfiguration->applicationRoot, $payload),
                default => throw new InstallationSettingsFailure('settings_group_invalid', 'Formvex could not identify the settings group being saved.'),
            };

            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $this->formData($snapshot), 'The ' . $group . ' settings were saved successfully.', 'success');
        } catch (AdministratorFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $this->administratorMessage($failure), 'danger');
        } catch (InstallationSettingsFailure $failure) {
            return $this->renderSettings($request, $this->sessionId($request) ?? '', $snapshot, $formData, $failure->getMessage(), 'danger', $formData['test_recipient'], $failure->fieldErrors);
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
    ): Response {
        $sessionId = $this->sessionId($request) ?? $sessionIdHash;
        $csrfToken = $this->csrfToken($request, $sessionId);
        $formData = array_merge($this->formData($snapshot), $formData);
        $response = $this->render('administration/settings.html.twig', [
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_settings',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => $snapshot->settings->websiteDisplayName,
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'pageTitle' => 'Settings',
            'pageDescription' => 'Configure the local website identity, SMTP delivery, and administrator security.',
            'settings' => $snapshot,
            'formData' => $formData,
            'testRecipient' => $testRecipient,
            'message' => $message,
            'messageVariant' => $variant,
            'fieldErrors' => $fieldErrors,
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
            'maximum_failures' => (string) $settings->loginThrottle->maximumFailures,
            'window_minutes' => (string) $settings->loginThrottle->windowMinutes,
            'cooldown_minutes' => (string) $settings->loginThrottle->cooldownMinutes,
            'test_recipient' => '',
        ];
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
