<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Submission;

use Formvex\Spoke\Admin\Portal\PaginationView;
use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\Storage\StorageExportService;
use Formvex\Spoke\Application\SubmissionReview\SubmissionReviewService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\Storage\StorageExportFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Formvex\Spoke\Infrastructure\Security\AdminActionTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubmissionReviewController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly SubmissionReviewService $reviewService,
        private readonly FormConfigurationService $formConfigurationService,
        private readonly AdminActionTokenManager $actionTokenManager,
        private readonly StorageExportService $storageExportService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/submissions', name: 'spoke_admin_submissions', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $query = SubmissionReviewQuery::fromInput($request->query->all());
        try {
            $result = $this->reviewService->list($this->runtimeConfiguration->applicationRoot, $query);
            $forms = $this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot);

            return $this->renderPage($request, 'administration/submissions/index.html.twig', [
                'query' => $query,
                'result' => $result,
                'forms' => $forms,
                'queryString' => $this->queryString($query),
                'pagination' => $this->pagination($query, $result->pageCount, $result->total),
                'pageSizeControl' => $this->pageSizeControl($query),
                'exportParameters' => $this->exportParameters($query),
                'exportId' => $this->exportId($request->query->get('export_id')),
                'message' => $query->errors === [] ? $this->notice($request->query->get('notice')) : implode(' ', $query->errors),
                'messageVariant' => $query->errors === [] ? 'information' : 'warning',
                'messageTitle' => $query->errors === [] ? 'Submission review' : 'Filter corrected',
            ]);
        } catch (SubmissionReviewFailure|FormConfigurationFailure $failure) {
            return $this->renderPage($request, 'administration/submissions/index.html.twig', [
                'query' => $query,
                'result' => null,
                'forms' => [],
                'queryString' => $this->queryString($query),
                'pagination' => ['pages' => [], 'previous' => null, 'next' => null],
                'message' => $failure->getMessage(),
                'messageVariant' => 'danger',
                'messageTitle' => 'Submissions could not be loaded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/formvex/submissions/export', name: 'spoke_admin_submissions_export', methods: ['POST'])]
    public function export(Request $request): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $token = $request->request->getString('_token');
            if (!$this->administratorService->csrfTokenMatches($context, $token)) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $query = $this->exportQuery($request);
            if ($query->errors !== []) {
                throw new StorageExportFailure('export_request_invalid', implode(' ', $query->errors));
            }
            $export = $this->storageExportService->generate($this->runtimeConfiguration->applicationRoot, $query);
            $parameters = array_merge($query->toQuery(), ['export_id' => $export->publicId]);

            return $this->redirectToRoute('spoke_admin_submissions', $parameters);
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_submissions', ['notice' => 'security']);
        } catch (StorageExportFailure $failure) {
            return $this->redirectToRoute('spoke_admin_submissions', ['notice' => $failure->failureCode]);
        }
    }

    #[Route('/formvex/exports/{exportId}/download', name: 'spoke_admin_export_download', methods: ['GET'])]
    public function downloadExport(Request $request, string $exportId): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $file = $this->storageExportService->download($this->runtimeConfiguration->applicationRoot, $exportId);
            $response = new BinaryFileResponse($file);
            $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
            $response->headers->set('Content-Disposition', 'attachment; filename="submissions-' . $exportId . '.csv"');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        } catch (StorageExportFailure $failure) {
            $status = $failure->failureCode === 'export_not_available' ? Response::HTTP_NOT_FOUND : Response::HTTP_SERVICE_UNAVAILABLE;

            return new Response($failure->getMessage(), $status, ['Cache-Control' => 'no-store, private']);
        }
    }

    #[Route('/formvex/submissions/{submissionId}', name: 'spoke_admin_submission_detail', methods: ['GET'])]
    public function detail(Request $request, string $submissionId): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $query = SubmissionReviewQuery::fromInput($request->query->all());
        try {
            $details = $this->reviewService->details($this->runtimeConfiguration->applicationRoot, $submissionId);
            $auditPagination = PaginationView::fromRequest($request, $details->auditEvents, 'audit_page', 'audit_page_size');

            return $this->renderPage($request, 'administration/submissions/detail.html.twig', [
                'details' => $details,
                'auditEvents' => $auditPagination['items'],
                'auditPagination' => $auditPagination,
                'queryString' => $this->queryString($query),
                'returnQuery' => $this->queryString($query),
                'actionTokens' => $this->actionTokens($submissionId),
                'csrfToken' => $this->csrfToken($request, (string) $request->cookies->get(self::SESSION_COOKIE)),
                'message' => $this->notice($request->query->get('notice')),
                'messageVariant' => 'information',
                'messageTitle' => 'Submission review',
            ]);
        } catch (SubmissionReviewFailure $failure) {
            $status = $failure->failureCode === 'not_found' ? Response::HTTP_NOT_FOUND : Response::HTTP_SERVICE_UNAVAILABLE;

            return $this->renderPage($request, 'administration/submissions/detail.html.twig', [
                'details' => null,
                'queryString' => $this->queryString($query),
                'returnQuery' => $this->queryString($query),
                'actionTokens' => [],
                'csrfToken' => $this->csrfToken($request, (string) $request->cookies->get(self::SESSION_COOKIE)),
                'message' => $failure->getMessage(),
                'messageVariant' => $status === Response::HTTP_NOT_FOUND ? 'warning' : 'danger',
                'messageTitle' => $status === Response::HTTP_NOT_FOUND ? 'Submission not found' : 'Submission could not be loaded',
            ], $status);
        }
    }

    #[Route('/formvex/submissions/{submissionId}/handled', name: 'spoke_admin_submission_handled', methods: ['POST'])]
    public function handled(Request $request, string $submissionId): Response
    {
        return $this->apply($request, $submissionId, SubmissionReviewAction::HANDLED);
    }

    #[Route('/formvex/submissions/{submissionId}/not-spam', name: 'spoke_admin_submission_not_spam', methods: ['POST'])]
    public function notSpam(Request $request, string $submissionId): Response
    {
        return $this->apply($request, $submissionId, SubmissionReviewAction::NOT_SPAM);
    }

    #[Route('/formvex/submissions/{submissionId}/trash', name: 'spoke_admin_submission_trash', methods: ['POST'])]
    public function trash(Request $request, string $submissionId): Response
    {
        return $this->apply($request, $submissionId, SubmissionReviewAction::TRASH);
    }

    #[Route('/formvex/submissions/{submissionId}/restore', name: 'spoke_admin_submission_restore', methods: ['POST'])]
    public function restore(Request $request, string $submissionId): Response
    {
        return $this->apply($request, $submissionId, SubmissionReviewAction::RESTORE);
    }

    #[Route('/formvex/submissions/{submissionId}/delete', name: 'spoke_admin_submission_delete', methods: ['POST'])]
    public function delete(Request $request, string $submissionId): Response
    {
        return $this->apply($request, $submissionId, SubmissionReviewAction::DELETE);
    }

    private function apply(Request $request, string $submissionId, SubmissionReviewAction $action): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $csrfToken = $request->request->getString('_token');
            $actionToken = $request->request->getString('action_token');
            if (!$this->administratorService->csrfTokenMatches($context, $csrfToken)
                || !$this->actionTokenManager->isValid($actionToken, $action->value, $submissionId)) {
                throw new AdministratorFailure('csrf_invalid');
            }
            if (in_array($action, [SubmissionReviewAction::TRASH, SubmissionReviewAction::DELETE], true)
                && $request->request->getString('confirm') !== '1') {
                throw new SubmissionReviewFailure('confirmation_required', 'This action requires explicit confirmation before it can be completed.');
            }

            $result = $this->reviewService->apply($this->runtimeConfiguration->applicationRoot, $submissionId, $action);
            $returnQuery = $this->safeReturnQuery($request->request->getString('return_query'));

            if (!$result->changed && $result->variant === 'danger') {
                return $this->detailWithMessage($request, $submissionId, $returnQuery, $result->message, 'danger');
            }

            if ($action === SubmissionReviewAction::DELETE && $result->changed) {
                $parameters = ['notice' => 'deleted'];
                parse_str($returnQuery, $safeQuery);
                $parameters = array_merge($safeQuery, $parameters);

                return $this->redirectToRoute('spoke_admin_submissions', $parameters);
            }

            $notice = $result->changed ? ($action === SubmissionReviewAction::HANDLED ? 'handled' : ($action === SubmissionReviewAction::NOT_SPAM ? 'not_spam' : $action->value)) : 'no_change';
            parse_str($returnQuery, $safeQuery);
            $safeQuery['notice'] = $notice;

            return $this->redirectToRoute('spoke_admin_submission_detail', array_merge(['submissionId' => $submissionId], $safeQuery));
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_submission_detail', ['submissionId' => $submissionId, 'notice' => 'security']);
        } catch (SubmissionReviewFailure $failure) {
            $notice = match ($failure->failureCode) {
                'confirmation_required' => 'confirmation_required',
                'not_found' => 'not_found',
                default => 'action_failed',
            };

            return $this->redirectToRoute('spoke_admin_submission_detail', ['submissionId' => $submissionId, 'notice' => $notice]);
        }
    }

    private function context(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== ''
            ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId)
            : null;
    }

    /** @return array<string, string> */
    private function actionTokens(string $submissionId): array
    {
        $tokens = [];
        foreach (SubmissionReviewAction::cases() as $action) {
            $tokens[$action->value] = $this->actionTokenManager->issue($action->value, $submissionId);
        }

        return $tokens;
    }

    private function csrfToken(Request $request, string $sessionId): string
    {
        $token = $request->cookies->get(self::CSRF_COOKIE);

        return is_string($token) && $token !== '' ? $token : $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId);
    }

    private function queryString(SubmissionReviewQuery $query): string
    {
        return http_build_query($query->toQuery(), '', '&', PHP_QUERY_RFC3986);
    }

    /** @return list<array{name: string, value: string}> */
    private function exportParameters(SubmissionReviewQuery $query): array
    {
        $parameters = [];
        foreach ($query->toQuery() as $name => $value) {
            $parameters[] = ['name' => $name, 'value' => $value];
        }

        return $parameters;
    }

    private function exportId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-fA-F-]{16,80}\z/', $value) === 1 ? $value : null;
    }

    private function exportQuery(Request $request): SubmissionReviewQuery
    {
        $allowed = ['form', 'classification', 'lifecycle', 'delivery', 'record_type', 'sort'];
        $input = [];
        foreach ($request->request->all() as $key => $value) {
            if ($key === '_token') {
                continue;
            }
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                throw new StorageExportFailure('export_request_invalid', 'The export request contained an unsupported filter. Reload Submissions and try again.');
            }
            $input[$key] = $value;
        }

        return SubmissionReviewQuery::fromInput($input);
    }

    /** @return array{action: string, id: string, name: string, label: string, value: int, options: list<int>, hidden: list<array{name: string, value: string}>} */
    private function pageSizeControl(SubmissionReviewQuery $query): array
    {
        $hidden = [];
        foreach ($query->toQuery() as $name => $value) {
            if ($name === 'page' || $name === 'page_size') {
                continue;
            }
            $hidden[] = ['name' => $name, 'value' => $value];
        }

        return [
            'action' => $this->generateUrl('spoke_admin_submissions'),
            'id' => 'submission-page-size',
            'name' => 'page_size',
            'label' => 'Rows per page',
            'value' => $query->pageSize,
            'options' => [25, 50, 100],
            'hidden' => $hidden,
        ];
    }

    /** @return array{currentPage: int, pageCount: int, total: int, firstItem: int, lastItem: int, pageParameter: string, pageSizeParameter: string, pages: list<array{page: int, href: string, current: bool}>, previous: array{href: string, disabled: bool}|null, next: array{href: string, disabled: bool}|null} */
    private function pagination(SubmissionReviewQuery $query, int $pageCount, int $total): array
    {
        if ($total <= 0) {
            return ['currentPage' => 1, 'pageCount' => 1, 'total' => 0, 'firstItem' => 0, 'lastItem' => 0, 'pageParameter' => 'page', 'pageSizeParameter' => 'page_size', 'pages' => [], 'previous' => null, 'next' => null];
        }
        $firstItem = (($query->page - 1) * $query->pageSize) + 1;
        $lastItem = min($query->page * $query->pageSize, $total);
        $start = max(1, $query->page - 2);
        $end = min($pageCount, $query->page + 2);
        $items = [];
        for ($page = $start; $page <= $end; $page++) {
            $items[] = ['page' => $page, 'href' => '?' . http_build_query($query->toQuery($page), '', '&', PHP_QUERY_RFC3986), 'current' => $page === $query->page];
        }

        return [
            'currentPage' => $query->page,
            'pageCount' => $pageCount,
            'total' => $total,
            'firstItem' => $firstItem,
            'lastItem' => $lastItem,
            'pageParameter' => 'page',
            'pageSizeParameter' => 'page_size',
            'pages' => $items,
            'previous' => [
                'href' => '?' . http_build_query($query->toQuery(max(1, $query->page - 1)), '', '&', PHP_QUERY_RFC3986),
                'disabled' => $query->page <= 1,
            ],
            'next' => [
                'href' => '?' . http_build_query($query->toQuery(min($pageCount, $query->page + 1)), '', '&', PHP_QUERY_RFC3986),
                'disabled' => $query->page >= $pageCount,
            ],
        ];
    }

    private function safeReturnQuery(string $value): string
    {
        if ($value === '' || strlen($value) > 1000) {
            return '';
        }
        $parsed = [];
        parse_str($value, $parsed);
        $input = [];
        foreach ($parsed as $key => $entry) {
            if (is_string($key)) {
                $input[$key] = $entry;
            }
        }

        return $this->queryString(SubmissionReviewQuery::fromInput($input));
    }

    private function detailWithMessage(Request $request, string $submissionId, string $returnQuery, string $message, string $variant): Response
    {
        try {
            $details = $this->reviewService->details($this->runtimeConfiguration->applicationRoot, $submissionId);

            return $this->renderPage($request, 'administration/submissions/detail.html.twig', [
                'details' => $details,
                'queryString' => $returnQuery,
                'returnQuery' => $returnQuery,
                'actionTokens' => $this->actionTokens($submissionId),
                'message' => $message,
                'messageVariant' => $variant,
                'messageTitle' => 'Action not completed',
            ]);
        } catch (SubmissionReviewFailure) {
            return $this->redirectToRoute('spoke_admin_submission_detail', ['submissionId' => $submissionId, 'notice' => 'not_found']);
        }
    }

    private function notice(mixed $notice): ?string
    {
        return match (is_string($notice) ? $notice : '') {
            'handled' => 'The submission was marked handled.',
            'not_spam' => 'The submission was marked not spam. No message was resent.',
            'trash' => 'The submission was moved to Trash and remains recoverable.',
            'restore' => 'The submission was restored to its previous review state.',
            'deleted' => 'The submission and its owned delivery records were permanently deleted.',
            'no_change' => 'The requested state was already applied. No change was made.',
            'confirmation_required' => 'Select the explicit confirmation control before continuing.',
            'security' => 'The security request could not be verified. Reload the page and try again.',
            'action_failed' => 'The action could not be completed. Review the current record state and try again.',
            'not_found' => 'The requested submission could not be found. It may have been removed or is no longer available.',
            'export_in_progress' => 'An export is already available for download. Download it or wait for it to expire before creating another export.',
            'export_limit_reached' => 'The selected result contains more than 10,000 records. Add filters and try the export again.',
            'storage_unavailable' => 'The CSV export could not be created because private storage is full or temporarily unavailable. Existing records were not changed.',
            'export_failed' => 'The CSV export could not be created. Existing submissions were not changed.',
            'export_request_invalid' => 'The export request contained an invalid or unsupported filter. Reload Submissions and try again.',
            default => null,
        };
    }

    /** @param array<string, mixed> $parameters */
    private function renderPage(Request $request, string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $sessionId = (string) $request->cookies->get(self::SESSION_COOKIE);
        $csrfToken = $this->csrfToken($request, $sessionId);
        $response = $this->render($template, array_merge([
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_submissions',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => 'Local Spoke',
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'navGroups' => PortalNavigation::groups(),
            'pageTitle' => 'Submissions',
            'pageDescription' => 'Review accepted messages, classification, lifecycle, and delivery state.',
        ], $parameters), new Response('', $status));

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie(Cookie::create(self::CSRF_COOKIE, $csrfToken, 0, '/formvex', null, true, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
