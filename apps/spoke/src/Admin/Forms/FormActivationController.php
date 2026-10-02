<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Forms;

use Formvex\Spoke\Admin\Portal\PaginationView;
use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\FormActivation\FormActivationService;
use Formvex\Spoke\Application\FormActivation\FormActivationStatusService;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\FormActivation\Exception\FormActivationFailure;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormActivationController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly FormConfigurationRequestResolver $requestResolver,
        private readonly FormConfigurationService $formConfigurationService,
        private readonly FormActivationService $formActivationService,
        private readonly FormActivationStatusService $formActivationStatusService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/forms/{publicFormId}/qualification/start', name: 'spoke_admin_form_qualification_start', methods: ['POST'])]
    public function startQualification(Request $request, string $publicFormId): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            [$csrfToken, $_revision] = $this->requestResolver->revision($request);
            $this->assertCsrf($context, $csrfToken);
            $sessionId = $request->cookies->get(self::SESSION_COOKIE);

            if (!is_string($sessionId) || $sessionId === '') {
                throw new AdministratorFailure('session_missing');
            }

            $authorization = $this->formActivationService->startQualification($this->runtimeConfiguration->applicationRoot, $sessionId, $publicFormId);

            return $this->renderState($request, $publicFormId, 'A one-time qualification link is ready. Open it on the configured HTTPS page, submit synthetic test data, then return here to review the result.', 'success', $authorization->qualificationUrl, $authorization->expiresAt->format(DATE_ATOM));
        } catch (AdministratorFailure) {
            return $this->renderFailure($request, $publicFormId, 'The form security request could not be verified. Reload the form and try again.');
        } catch (FormConfigurationFailure|FormActivationFailure $failure) {
            return $this->renderFailure($request, $publicFormId, $failure->getMessage());
        }
    }

    #[Route('/formvex/forms/{publicFormId}/activate', name: 'spoke_admin_form_activate', methods: ['POST'])]
    public function activate(Request $request, string $publicFormId): Response
    {
        return $this->stateAction($request, $publicFormId, 'activate');
    }

    #[Route('/formvex/forms/{publicFormId}/disable', name: 'spoke_admin_form_disable', methods: ['POST'])]
    public function disable(Request $request, string $publicFormId): Response
    {
        return $this->stateAction($request, $publicFormId, 'disable');
    }

    private function stateAction(Request $request, string $publicFormId, string $action): Response
    {
        $context = $this->context($request);

        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            [$csrfToken, $_revision] = $this->requestResolver->revision($request);
            $this->assertCsrf($context, $csrfToken);

            if ($action === 'activate') {
                $version = $this->formActivationService->activate($this->runtimeConfiguration->applicationRoot, $publicFormId);
                $message = 'Configuration version ' . $version->versionNumber . ' is active. New public submissions now resolve this version.';
            } else {
                $this->formActivationService->disable($this->runtimeConfiguration->applicationRoot, $publicFormId);
                $message = 'Form intake is disabled. Previously accepted delivery jobs remain queued for processing.';
            }

            return $this->renderState($request, $publicFormId, $message, 'success');
        } catch (AdministratorFailure) {
            return $this->renderFailure($request, $publicFormId, 'The form security request could not be verified. Reload the form and try again.');
        } catch (FormConfigurationFailure|FormActivationFailure $failure) {
            return $this->renderFailure($request, $publicFormId, $failure->getMessage());
        }
    }

    private function context(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== ''
            ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId)
            : null;
    }

    private function assertCsrf(SessionRecord $session, string $token): void
    {
        if (!$this->administratorService->csrfTokenMatches($session, $token)) {
            throw new AdministratorFailure('csrf_invalid');
        }
    }

    private function renderState(Request $request, string $publicFormId, string $message, string $variant, ?string $qualificationUrl = null, ?string $qualificationExpiresAt = null): Response
    {
        $details = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId, true);

        return $this->renderPage($request, [
            'details' => $details,
            'activation' => $this->formActivationStatusService->status($this->runtimeConfiguration->applicationRoot, $publicFormId),
            'formData' => $this->formData($details->draft),
            'fieldsJson' => $this->fieldsJson($details->draft),
            'fieldErrors' => [],
            'message' => $message,
            'messageVariant' => $variant,
            'qualificationUrl' => $qualificationUrl,
            'qualificationExpiresAt' => $qualificationExpiresAt,
            'isNew' => false,
        ]);
    }

    private function renderFailure(Request $request, string $publicFormId, string $message): Response
    {
        try {
            return $this->renderState($request, $publicFormId, $message, 'danger');
        } catch (FormConfigurationFailure) {
            return new Response($message, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function renderPage(Request $request, array $parameters): Response
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);
        $sessionId = is_string($sessionId) ? $sessionId : '';
        $csrfToken = $request->cookies->get(self::CSRF_COOKIE);
        $csrfToken = is_string($csrfToken) && $csrfToken !== ''
            ? $csrfToken
            : $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId);
        $parameters = array_merge([
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_forms',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => 'Local Spoke',
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'navGroups' => PortalNavigation::groups(),
            'pageTitle' => 'Forms',
            'pageDescription' => 'Create, review, publish, and retire local form configurations.',
        ], $parameters);

        if (($details = $parameters['details'] ?? null) instanceof FormConfigurationDetails) {
            $versionPagination = PaginationView::fromRequest($request, $details->publishedVersions, 'version_page', 'version_page_size');
            $parameters['publishedVersions'] = $versionPagination['items'];
            $parameters['versionPagination'] = $versionPagination;
        }

        $response = $this->render('administration/forms/form.html.twig', $parameters);

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie(Cookie::create(self::CSRF_COOKIE, $csrfToken, 0, '/formvex', null, true, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    /** @return array<string, string> */
    private function formData(FormConfigurationRecord $draft): array
    {
        return [
            'display_name' => $draft->displayName,
            'page_host' => $draft->page->host,
            'page_path' => $draft->page->path,
            'form_marker' => $draft->page->formMarker,
            'recipient' => $draft->recipient,
            'subject' => $draft->subject,
            'captcha_enabled' => $draft->captchaEnabled ? '1' : '0',
            'captcha_site_key' => $draft->captchaSiteKey,
            'revision' => (string) $draft->revision,
        ];
    }

    private function fieldsJson(FormConfigurationRecord $draft): string
    {
        $fields = array_map(static fn ($field): array => [
            'field_key' => $field->fieldKey,
            'control_name' => $field->controlName,
            'control_type' => $field->controlType,
            'display_label' => $field->displayLabel,
            'parameter_key' => $field->parameterKey,
            'ordinal' => $field->ordinal,
            'required' => $field->required,
            'max_length' => $field->maxLength,
            'choices' => array_map(static fn ($choice): array => ['value' => $choice->value, 'label' => $choice->label], $field->choices),
        ], $draft->fields);

        try {
            return json_encode($fields, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[]';
        }
    }
}
