<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Forms;

use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\FormDiscovery\FormDiscoveryService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDetails;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\FormDiscovery\DiscoveryCandidate;
use Formvex\Spoke\Domain\FormDiscovery\Exception\FormDiscoveryFailure;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FormConfigurationController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly FormConfigurationRequestResolver $requestResolver,
        private readonly FormDiscoveryRequestResolver $discoveryRequestResolver,
        private readonly FormConfigurationService $formConfigurationService,
        private readonly FormDiscoveryService $formDiscoveryService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/forms', name: 'spoke_admin_forms', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            return $this->renderPage($request, 'administration/forms/index.html.twig', [
                'forms' => $this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot),
                'trashCount' => count($this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot, true)) - count($this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot)),
                'message' => null,
                'messageVariant' => 'information',
            ]);
        } catch (FormConfigurationFailure $failure) {
            return $this->renderPage($request, 'administration/forms/index.html.twig', [
                'forms' => [],
                'trashCount' => 0,
                'message' => $failure->getMessage(),
                'messageVariant' => 'danger',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/formvex/forms/trash', name: 'spoke_admin_forms_trash', methods: ['GET'])]
    public function trashIndex(Request $request): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        return $this->renderPage($request, 'administration/forms/trash.html.twig', [
            'forms' => $this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot, true),
            'message' => null,
            'messageVariant' => 'information',
        ]);
    }

    #[Route('/formvex/forms/new', name: 'spoke_admin_form_new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        return $this->renderPage($request, 'administration/forms/form.html.twig', [
            'details' => null,
            'formData' => $this->emptyFormData(),
            'fieldsJson' => '',
            'fieldErrors' => [],
            'message' => null,
            'messageVariant' => 'information',
            'isNew' => true,
        ]);
    }

    #[Route('/formvex/forms', name: 'spoke_admin_form_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        try {
            $draft = $this->requestResolver->draft($request, false);
            $this->assertCsrf($context, $draft->csrfToken);
            $details = $this->formConfigurationService->create($this->runtimeConfiguration->applicationRoot, $draft->data);

            return $this->redirectToRoute('spoke_admin_form_edit', ['publicFormId' => $details->draft->publicId]);
        } catch (AdministratorFailure $failure) {
            return $this->renderFormFailure($request, null, $failure->getMessage(), 'danger');
        } catch (FormConfigurationFailure $failure) {
            return $this->renderFormFailure($request, null, $failure->getMessage(), 'danger', $failure->fieldErrors);
        }
    }

    #[Route('/formvex/forms/new/discovery', name: 'spoke_admin_form_discovery_new_start', methods: ['POST'])]
    public function startNewDiscovery(Request $request): Response
    {
        return $this->startDiscovery($request, null);
    }

    #[Route('/formvex/forms/{publicFormId}/discovery', name: 'spoke_admin_form_discovery_start', methods: ['POST'])]
    public function startExistingDiscovery(Request $request, string $publicFormId): Response
    {
        return $this->startDiscovery($request, $publicFormId);
    }

    #[Route('/formvex/forms/new/discovery', name: 'spoke_admin_form_discovery_new_review', methods: ['GET'])]
    public function reviewNewDiscovery(Request $request): Response
    {
        return $this->reviewDiscovery($request, null);
    }

    #[Route('/formvex/forms/{publicFormId}/discovery', name: 'spoke_admin_form_discovery_review', methods: ['GET'])]
    public function reviewExistingDiscovery(Request $request, string $publicFormId): Response
    {
        return $this->reviewDiscovery($request, $publicFormId);
    }

    #[Route('/formvex/forms/new/discovery/apply', name: 'spoke_admin_form_discovery_new_apply', methods: ['POST'])]
    public function applyNewDiscovery(Request $request): Response
    {
        return $this->applyDiscovery($request, null);
    }

    #[Route('/formvex/forms/{publicFormId}/discovery/apply', name: 'spoke_admin_form_discovery_apply', methods: ['POST'])]
    public function applyExistingDiscovery(Request $request, string $publicFormId): Response
    {
        return $this->applyDiscovery($request, $publicFormId);
    }

    #[Route('/formvex/forms/new/discovery/discard', name: 'spoke_admin_form_discovery_new_discard', methods: ['POST'])]
    public function discardNewDiscovery(Request $request): Response
    {
        return $this->discardDiscovery($request, null);
    }

    #[Route('/formvex/forms/{publicFormId}/discovery/discard', name: 'spoke_admin_form_discovery_discard', methods: ['POST'])]
    public function discardExistingDiscovery(Request $request, string $publicFormId): Response
    {
        return $this->discardDiscovery($request, $publicFormId);
    }

    #[Route('/formvex/forms/{publicFormId}', name: 'spoke_admin_form_edit', methods: ['GET'])]
    public function edit(Request $request, string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $details = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId, true);

            return $this->renderPage($request, 'administration/forms/form.html.twig', [
                'details' => $details,
                'formData' => $this->formData($details->draft),
                'fieldsJson' => $this->fieldsJson($details->draft),
                'fieldErrors' => [],
                'message' => null,
                'messageVariant' => 'information',
                'isNew' => false,
            ]);
        } catch (FormConfigurationFailure $failure) {
            return new Response($failure->getMessage(), Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/formvex/forms/{publicFormId}', name: 'spoke_admin_form_update', methods: ['POST'])]
    public function update(Request $request, string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        try {
            $draft = $this->requestResolver->draft($request, true);
            $this->assertCsrf($context, $draft->csrfToken);
            $revision = $draft->revision;

            if ($revision === null) {
                throw new FormConfigurationFailure('draft_revision_invalid', 'Reload the latest form draft before saving it.');
            }

            $details = $this->formConfigurationService->update($this->runtimeConfiguration->applicationRoot, $publicFormId, $revision, $draft->data);

            return $this->renderPage($request, 'administration/forms/form.html.twig', [
                'details' => $details,
                'formData' => $this->formData($details->draft),
                'fieldsJson' => $this->fieldsJson($details->draft),
                'fieldErrors' => [],
                'message' => 'The form draft was saved. It is not publicly active until a later activation step.',
                'messageVariant' => 'success',
                'isNew' => false,
            ]);
        } catch (AdministratorFailure $failure) {
            return $this->renderFormFailure($request, $publicFormId, 'The form security request could not be verified. Reload the form and try again.', 'danger');
        } catch (FormConfigurationFailure $failure) {
            return $this->renderFormFailure($request, $publicFormId, $failure->getMessage(), 'danger', $failure->fieldErrors);
        }
    }

    #[Route('/formvex/forms/{publicFormId}/publish', name: 'spoke_admin_form_publish', methods: ['POST'])]
    public function publish(Request $request, string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        try {
            [$csrfToken, $revision] = $this->requestResolver->revision($request);
            $this->assertCsrf($context, $csrfToken);
            $published = $this->formConfigurationService->publish($this->runtimeConfiguration->applicationRoot, $publicFormId, $revision);
            $details = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId);

            return $this->renderPage($request, 'administration/forms/form.html.twig', [
                'details' => $details,
                'formData' => $this->formData($details->draft),
                'fieldsJson' => $this->fieldsJson($details->draft),
                'fieldErrors' => [],
                'message' => 'Configuration version ' . $published->versionNumber . ' was published. Unit 12 must activate it before public resolution can match it.',
                'messageVariant' => 'success',
                'isNew' => false,
            ]);
        } catch (AdministratorFailure) {
            return $this->renderFormFailure($request, $publicFormId, 'The form security request could not be verified. Reload the form and try again.', 'danger');
        } catch (FormConfigurationFailure $failure) {
            return $this->renderFormFailure($request, $publicFormId, $failure->getMessage(), 'danger', $failure->fieldErrors);
        }
    }

    #[Route('/formvex/forms/{publicFormId}/trash', name: 'spoke_admin_form_trash', methods: ['POST'])]
    public function moveToTrash(Request $request, string $publicFormId): Response
    {
        return $this->lifecycleAction($request, $publicFormId, 'trash');
    }

    #[Route('/formvex/forms/{publicFormId}/restore', name: 'spoke_admin_form_restore', methods: ['POST'])]
    public function restore(Request $request, string $publicFormId): Response
    {
        return $this->lifecycleAction($request, $publicFormId, 'restore');
    }

    #[Route('/formvex/forms/{publicFormId}/delete', name: 'spoke_admin_form_hard_delete', methods: ['POST'])]
    public function hardDelete(Request $request, string $publicFormId): Response
    {
        return $this->lifecycleAction($request, $publicFormId, 'hard_delete');
    }

    private function lifecycleAction(Request $request, string $publicFormId, string $action): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        try {
            [$csrfToken, $_revision, $confirmed] = $this->requestResolver->lifecycle($request);
            $this->assertCsrf($context, $csrfToken);

            if ($action === 'hard_delete' && !$confirmed) {
                throw new FormConfigurationFailure('hard_delete_confirmation_required', 'Permanently deleting a form requires explicit confirmation.');
            }

            match ($action) {
                'trash' => $this->formConfigurationService->trash($this->runtimeConfiguration->applicationRoot, $publicFormId),
                'restore' => $this->formConfigurationService->restore($this->runtimeConfiguration->applicationRoot, $publicFormId),
                'hard_delete' => $this->formConfigurationService->hardDelete($this->runtimeConfiguration->applicationRoot, $publicFormId),
                default => throw new LogicException('Unknown form lifecycle action.'),
            };

            return $this->redirectToRoute($action === 'restore' ? 'spoke_admin_form_edit' : 'spoke_admin_forms', $action === 'restore' ? ['publicFormId' => $publicFormId] : []);
        } catch (AdministratorFailure) {
            return new Response('The form security request could not be verified. Reload the page and try again.', Response::HTTP_BAD_REQUEST);
        } catch (FormConfigurationFailure $failure) {
            return new Response($failure->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function startDiscovery(Request $request, ?string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $this->discoveryRequestResolver->start($request);
            $this->assertCsrf($context, $payload['csrf_token']);
            $sessionId = $request->cookies->get(self::SESSION_COOKIE);

            if (!is_string($sessionId) || $sessionId === '') {
                throw new AdministratorFailure('session_missing');
            }

            $authorization = $this->formDiscoveryService->begin($this->runtimeConfiguration->applicationRoot, $sessionId, $payload['host'], $payload['path']);

            return $this->renderDiscovery($request, $publicFormId, $authorization->discoveryUrl, $authorization->expiresAt->format(DATE_ATOM), null);
        } catch (AdministratorFailure) {
            return $this->renderDiscovery($request, $publicFormId, null, null, 'The discovery security request could not be verified. Reload the page and try again.');
        } catch (FormConfigurationFailure|FormDiscoveryFailure $failure) {
            return $this->renderDiscovery($request, $publicFormId, null, null, $failure->getMessage());
        }
    }

    private function reviewDiscovery(Request $request, ?string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            return $this->renderDiscovery($request, $publicFormId, null, null, null);
        } catch (FormDiscoveryFailure $failure) {
            return $this->renderDiscovery($request, $publicFormId, null, null, $failure->getMessage());
        }
    }

    private function applyDiscovery(Request $request, ?string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $this->discoveryRequestResolver->apply($request, $publicFormId !== null);
            $this->assertCsrf($context, $payload['csrf_token']);
            $fields = $this->requestResolver->fieldDefinitions($payload['fields_json']);
            $sessionId = $request->cookies->get(self::SESSION_COOKIE);

            if (!is_string($sessionId) || $sessionId === '') {
                throw new AdministratorFailure('session_missing');
            }

            $details = $this->formDiscoveryService->applyCandidate($this->runtimeConfiguration->applicationRoot, $sessionId, $payload['candidate_id'], $publicFormId, $payload['selected_form_index'], $payload['revision'], $fields);

            return $this->redirectToRoute('spoke_admin_form_edit', ['publicFormId' => $details->draft->publicId]);
        } catch (AdministratorFailure) {
            return $this->renderDiscovery($request, $publicFormId, null, null, 'The discovery security request could not be verified. Reload the page and try again.');
        } catch (FormConfigurationFailure|FormDiscoveryFailure $failure) {
            return $this->renderDiscovery($request, $publicFormId, null, null, $failure->getMessage());
        }
    }

    private function discardDiscovery(Request $request, ?string $publicFormId): Response
    {
        if (($context = $this->authenticatedContext($request)) === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }

        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $payload = $this->discoveryRequestResolver->apply($request, false);
            $this->assertCsrf($context, $payload['csrf_token']);
            $sessionId = $request->cookies->get(self::SESSION_COOKIE);

            if (!is_string($sessionId) || $sessionId === '') {
                throw new AdministratorFailure('session_missing');
            }

            $this->formDiscoveryService->discard($this->runtimeConfiguration->applicationRoot, $sessionId, $payload['candidate_id']);

            return $this->redirectToRoute($publicFormId === null ? 'spoke_admin_form_discovery_new_review' : 'spoke_admin_form_discovery_review', $publicFormId === null ? [] : ['publicFormId' => $publicFormId]);
        } catch (AdministratorFailure) {
            return new Response('The discovery security request could not be verified. Reload the page and try again.', Response::HTTP_BAD_REQUEST);
        } catch (FormDiscoveryFailure $failure) {
            return new Response($failure->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function renderDiscovery(Request $request, ?string $publicFormId, ?string $discoveryUrl, ?string $expiresAt, ?string $message): Response
    {
        $candidates = [];
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        if (is_string($sessionId) && $sessionId !== '') {
            try {
                $candidates = $this->formDiscoveryService->candidates($this->runtimeConfiguration->applicationRoot, $sessionId);
            } catch (FormDiscoveryFailure) {
                $candidates = [];
            }
        }

        $candidate = $candidates[0] ?? null;
        $existingDetails = null;

        if ($publicFormId !== null) {
            try {
                $existingDetails = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId, true);
            } catch (FormConfigurationFailure) {
                $existingDetails = null;
            }
        }

        $draftRevision = 1;

        if ($publicFormId !== null) {
            try {
                $draftRevision = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId)->draft->revision;
            } catch (FormConfigurationFailure) {
                $draftRevision = 1;
            }
        }

        return $this->renderPage($request, 'administration/forms/discovery.html.twig', [
            'publicFormId' => $publicFormId,
            'candidates' => $candidates,
            'candidate' => $candidate,
            'candidateFieldsJson' => $this->candidateFieldsJson($candidate, 0, $existingDetails?->draft),
            'candidateFieldsByForm' => $this->candidateFieldsByForm($candidate, $existingDetails?->draft),
            'removedMappingsByForm' => $this->removedMappingsByForm($candidate, $existingDetails?->draft),
            'draftRevision' => $draftRevision,
            'discoveryUrl' => $discoveryUrl,
            'expiresAt' => $expiresAt,
            'message' => $message,
            'messageVariant' => $message === null ? 'information' : 'danger',
        ]);
    }

    /** @return list<string> */
    private function candidateFieldsByForm(?DiscoveryCandidate $candidate, ?FormConfigurationRecord $existingDraft): array
    {
        if ($candidate === null) {
            return [];
        }

        $fields = [];

        foreach (array_keys($candidate->forms) as $index) {
            $fields[] = $this->candidateFieldsJson($candidate, (int) $index, $existingDraft);
        }

        return $fields;
    }

    private function candidateFieldsJson(?DiscoveryCandidate $candidate, int $formIndex, ?FormConfigurationRecord $existingDraft = null): string
    {
        $form = $candidate?->forms[$formIndex] ?? null;

        if ($form === null) {
            return '';
        }

        $fields = [];

        $existingByControl = [];

        if ($existingDraft !== null) {
            foreach ($existingDraft->fields as $existingField) {
                $existingByControl[$existingField->controlName . '|' . $existingField->controlType] = $existingField;
            }
        }

        foreach ($form->controls as $ordinal => $control) {
            $existing = $existingByControl[$control->controlName . '|' . $control->controlType] ?? null;
            $choices = $control->choices;

            if ($existing !== null) {
                $oldLabels = [];

                foreach ($existing->choices as $choice) {
                    $oldLabels[$choice->value] = $choice->label;
                }

                $choices = array_map(static fn (array $choice): array => [
                    'value' => $choice['value'],
                    'label' => $oldLabels[$choice['value']] ?? $choice['label'],
                ], $choices);
            }

            $parameter = count($control->suggestedParameters) === 1
                ? $control->suggestedParameters[0]
                : 'unmapped';

            if ($existing !== null) {
                $parameter = $existing->parameterKey;
            }

            $displayLabel = $control->displayLabel;
            $required = $control->required;
            $maxLength = $control->maxLength;

            if ($existing !== null) {
                $displayLabel = $existing->displayLabel;
                $required = $existing->required;
                $maxLength = $existing->maxLength;
            }

            $fields[] = [
                'field_key' => $control->discoveryKey,
                'control_name' => $control->controlName,
                'control_type' => $control->controlType,
                'display_label' => $displayLabel,
                'parameter_key' => $parameter,
                'ordinal' => $ordinal,
                'required' => $required,
                'max_length' => $maxLength,
                'choices' => $choices,
            ];
        }

        return json_encode($fields, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** @return list<list<string>> */
    private function removedMappingsByForm(?DiscoveryCandidate $candidate, ?FormConfigurationRecord $existingDraft): array
    {
        if ($candidate === null) {
            return [];
        }

        if ($existingDraft === null) {
            return array_fill(0, count($candidate->forms), []);
        }

        $removedByForm = [];

        foreach ($candidate->forms as $form) {
            $controlKeys = [];

            foreach ($form->controls as $control) {
                $controlKeys[$control->controlName . '|' . $control->controlType] = true;
            }

            $removed = [];

            foreach ($existingDraft->fields as $field) {
                if (!isset($controlKeys[$field->controlName . '|' . $field->controlType])) {
                    $removed[] = $field->displayLabel . ' (' . $field->controlName . ')';
                }
            }

            $removedByForm[] = $removed;
        }

        return $removedByForm;
    }

    /** @return SessionRecord|null */
    private function authenticatedContext(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);
        $session = is_string($sessionId) && $sessionId !== ''
            ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId)
            : null;

        return $session;
    }

    private function assertCsrf(SessionRecord $session, string $token): void
    {
        if (!$this->administratorService->csrfTokenMatches($session, $token)) {
            throw new AdministratorFailure('csrf_invalid');
        }
    }

    /** @param array<string, mixed> $extra */
    private function renderPage(Request $request, string $template, array $extra, int $status = Response::HTTP_OK): Response
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);
        $sessionId = is_string($sessionId) ? $sessionId : '';
        $csrfToken = $this->csrfToken($request, $sessionId);
        $response = $this->render($template, array_merge([
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_forms',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => 'Local Spoke',
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'pageTitle' => 'Forms',
            'pageDescription' => 'Create, review, publish, and retire local form configurations.',
        ], $extra), new Response('', $status));

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie($this->cookie(self::CSRF_COOKIE, $csrfToken));
        }

        return $response;
    }

    /** @param array<string, string> $fieldErrors */
    private function renderFormFailure(Request $request, ?string $publicFormId, string $message, string $variant, array $fieldErrors = []): Response
    {
        if ($publicFormId === null) {
            $formData = $this->emptyFormData();
            $details = null;
            $fieldsJson = '';
        } else {
            try {
                $details = $this->formConfigurationService->details($this->runtimeConfiguration->applicationRoot, $publicFormId);
                $formData = $this->formData($details->draft);
                $fieldsJson = $this->fieldsJson($details->draft);
            } catch (FormConfigurationFailure) {
                $details = null;
                $formData = $this->emptyFormData();
                $fieldsJson = '';
            }
        }

        foreach ($request->request->all() as $key => $value) {
            if (is_string($value) && array_key_exists($key, $formData)) {
                $formData[$key] = $value;
            }
        }

        if (is_string($request->request->get('fields_json'))) {
            $fieldsJson = $request->request->get('fields_json');
        }

        return $this->renderPage($request, 'administration/forms/form.html.twig', [
            'details' => $details,
            'formData' => $formData,
            'fieldsJson' => $fieldsJson,
            'fieldErrors' => $fieldErrors,
            'message' => $message,
            'messageVariant' => $variant,
            'isNew' => $publicFormId === null,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array<string, string> */
    private function emptyFormData(): array
    {
        return [
            'display_name' => '',
            'page_host' => '',
            'page_path' => '/',
            'form_marker' => '',
            'recipient' => '',
            'subject' => '',
            'revision' => '',
        ];
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
            'revision' => (string) $draft->revision,
        ];
    }

    private function fieldsJson(FormConfigurationRecord $draft): string
    {
        $fields = [];

        foreach ($draft->fields as $field) {
            $fields[] = [
                'field_key' => $field->fieldKey,
                'control_name' => $field->controlName,
                'control_type' => $field->controlType,
                'display_label' => $field->displayLabel,
                'parameter_key' => $field->parameterKey,
                'ordinal' => $field->ordinal,
                'required' => $field->required,
                'max_length' => $field->maxLength,
                'choices' => array_map(static fn ($choice): array => ['value' => $choice->value, 'label' => $choice->label], $field->choices),
            ];
        }

        return json_encode($fields, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
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
}
