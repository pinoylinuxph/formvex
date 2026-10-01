<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Submission;

use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Application\SubmissionReview\SubmissionReviewService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewAction;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewFailure;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Formvex\Spoke\Infrastructure\Security\AdminActionTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
                'pagination' => $this->pagination($query, $result->pageCount),
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
                'pagination' => [],
                'message' => $failure->getMessage(),
                'messageVariant' => 'danger',
                'messageTitle' => 'Submissions could not be loaded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
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

            return $this->renderPage($request, 'administration/submissions/detail.html.twig', [
                'details' => $details,
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

    /** @return list<array{page: int, href: string, current: bool}> */
    private function pagination(SubmissionReviewQuery $query, int $pageCount): array
    {
        if ($pageCount <= 1) {
            return [];
        }
        $start = max(1, $query->page - 2);
        $end = min($pageCount, $query->page + 2);
        $items = [];
        for ($page = $start; $page <= $end; $page++) {
            $items[] = ['page' => $page, 'href' => '?' . http_build_query($query->toQuery($page), '', '&', PHP_QUERY_RFC3986), 'current' => $page === $query->page];
        }

        return $items;
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
            'pageTitle' => 'Submissions',
            'pageDescription' => 'Review accepted messages, classification, lifecycle, and delivery state.',
        ], $parameters), new Response('', $status));

        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie(Cookie::create(self::CSRF_COOKIE, $csrfToken, 0, '/formvex', null, true, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }
}
