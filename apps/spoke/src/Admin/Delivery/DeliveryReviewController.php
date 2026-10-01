<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Delivery;

use Formvex\Spoke\Admin\Portal\PortalNavigation;
use Formvex\Spoke\Admin\Portal\PortalPreferences;
use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\DeliveryReview\DeliveryReviewService;
use Formvex\Spoke\Application\FormConfiguration\FormConfigurationService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewFailure;
use Formvex\Spoke\Domain\Delivery\DeliveryReviewQuery;
use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Formvex\Spoke\Infrastructure\Security\AdminActionTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryReviewController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    private const CSRF_COOKIE = 'formvex_admin_csrf';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly DeliveryReviewService $reviewService,
        private readonly FormConfigurationService $formConfigurationService,
        private readonly AdminActionTokenManager $actionTokenManager,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/delivery', name: 'spoke_admin_delivery', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        $query = DeliveryReviewQuery::fromInput($request->query->all());
        try {
            $result = $this->reviewService->list($this->runtimeConfiguration->applicationRoot, $query);
            $forms = $this->formConfigurationService->list($this->runtimeConfiguration->applicationRoot);

            return $this->renderPage($request, 'administration/delivery/index.html.twig', [
                'query' => $query,
                'result' => $result,
                'forms' => $forms,
                'warnings' => $this->reviewService->warnings($this->runtimeConfiguration->applicationRoot),
                'pagination' => $this->pagination($query, $result->pageCount, $result->total),
                'pageSizeControl' => $this->pageSizeControl($query),
                'message' => $query->errors === [] ? null : implode(' ', $query->errors),
                'messageVariant' => $query->errors === [] ? 'information' : 'warning',
                'messageTitle' => $query->errors === [] ? 'Delivery' : 'Filter corrected',
            ]);
        } catch (DeliveryReviewFailure|FormConfigurationFailure $failure) {
            return $this->renderPage($request, 'administration/delivery/index.html.twig', [
                'query' => $query,
                'result' => null,
                'forms' => [],
                'warnings' => [],
                'pagination' => ['pages' => [], 'previous' => null, 'next' => null],
                'pageSizeControl' => $this->pageSizeControl($query),
                'message' => $failure->getMessage(),
                'messageVariant' => 'danger',
                'messageTitle' => 'Delivery could not be loaded',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/formvex/delivery/{deliveryId}', name: 'spoke_admin_delivery_detail', methods: ['GET'])]
    public function detail(Request $request, string $deliveryId): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            $details = $this->reviewService->details($this->runtimeConfiguration->applicationRoot, $deliveryId);

            return $this->renderPage($request, 'administration/delivery/detail.html.twig', [
                'details' => $details,
                'warnings' => $this->reviewService->warnings($this->runtimeConfiguration->applicationRoot),
                'actionToken' => $this->actionTokenManager->issue('delivery_resend', $deliveryId),
                'message' => $this->notice($request->query->get('notice')),
                'messageVariant' => $this->noticeVariant($request->query->get('notice')),
                'messageTitle' => 'Delivery review',
            ]);
        } catch (DeliveryReviewFailure $failure) {
            $status = $failure->failureCode === 'not_found' ? Response::HTTP_NOT_FOUND : Response::HTTP_SERVICE_UNAVAILABLE;

            return $this->renderPage($request, 'administration/delivery/detail.html.twig', [
                'details' => null,
                'warnings' => [],
                'actionToken' => '',
                'message' => $failure->getMessage(),
                'messageVariant' => $status === Response::HTTP_NOT_FOUND ? 'warning' : 'danger',
                'messageTitle' => $status === Response::HTTP_NOT_FOUND ? 'Delivery not found' : 'Delivery could not be loaded',
            ], $status);
        }
    }

    #[Route('/formvex/delivery/{deliveryId}/resend', name: 'spoke_admin_delivery_resend', methods: ['POST'])]
    public function resend(Request $request, string $deliveryId): Response
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
            $actionToken = $request->request->getString('action_token');
            if (!$this->administratorService->csrfTokenMatches($context, $token)
                || !$this->actionTokenManager->isValid($actionToken, 'delivery_resend', $deliveryId)) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $confirmUncertain = $request->request->getString('confirm_uncertain') === '1';
            $this->reviewService->resend($this->runtimeConfiguration->applicationRoot, $deliveryId, $confirmUncertain);

            return $this->redirectToRoute('spoke_admin_delivery_detail', ['deliveryId' => $deliveryId, 'notice' => 'resend_queued']);
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_delivery_detail', ['deliveryId' => $deliveryId, 'notice' => 'security']);
        } catch (DeliveryReviewFailure $failure) {
            $notice = match ($failure->failureCode) {
                'uncertain_confirmation_required' => 'uncertain_confirmation_required',
                'resend_limit_reached' => 'resend_limit_reached',
                'not_eligible' => 'not_eligible',
                'stale_state' => 'stale_state',
                'not_found' => 'not_found',
                default => 'resend_failed',
            };

            return $this->redirectToRoute('spoke_admin_delivery_detail', ['deliveryId' => $deliveryId, 'notice' => $notice]);
        }
    }

    private function context(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== ''
            ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId)
            : null;
    }

    /** @return array<string, mixed> */
    private function pageSizeControl(DeliveryReviewQuery $query): array
    {
        $hidden = [];
        foreach ($query->toQuery() as $name => $value) {
            if (!in_array($name, ['page', 'page_size'], true)) {
                $hidden[] = ['name' => $name, 'value' => $value];
            }
        }

        return ['action' => $this->generateUrl('spoke_admin_delivery'), 'id' => 'delivery-page-size', 'name' => 'page_size', 'value' => $query->pageSize, 'options' => [25, 50, 100], 'hidden' => $hidden];
    }

    /** @return array<string, mixed> */
    private function pagination(DeliveryReviewQuery $query, int $pageCount, int $total): array
    {
        if ($total <= 0) {
            return ['currentPage' => 1, 'pageCount' => 1, 'total' => 0, 'firstItem' => 0, 'lastItem' => 0, 'pageParameter' => 'page', 'pageSizeParameter' => 'page_size', 'pages' => [], 'previous' => null, 'next' => null];
        }
        $items = [];
        for ($page = max(1, $query->page - 2); $page <= min($pageCount, $query->page + 2); $page++) {
            $items[] = ['page' => $page, 'href' => '?' . http_build_query($query->toQuery($page), '', '&', PHP_QUERY_RFC3986), 'current' => $page === $query->page];
        }

        return [
            'currentPage' => $query->page,
            'pageCount' => $pageCount,
            'total' => $total,
            'firstItem' => (($query->page - 1) * $query->pageSize) + 1,
            'lastItem' => min($query->page * $query->pageSize, $total),
            'pageParameter' => 'page',
            'pageSizeParameter' => 'page_size',
            'pages' => $items,
            'previous' => ['href' => '?' . http_build_query($query->toQuery(max(1, $query->page - 1)), '', '&', PHP_QUERY_RFC3986), 'disabled' => $query->page <= 1],
            'next' => ['href' => '?' . http_build_query($query->toQuery(min($pageCount, $query->page + 1)), '', '&', PHP_QUERY_RFC3986), 'disabled' => $query->page >= $pageCount],
        ];
    }

    private function notice(mixed $notice): ?string
    {
        return match (is_string($notice) ? $notice : '') {
            'resend_queued' => 'A new delivery attempt cycle was queued. The delivery worker will process it on its next run.',
            'uncertain_confirmation_required' => 'Confirm that you understand this resend may deliver the same email twice before continuing.',
            'resend_limit_reached' => 'Manual resend limit reached. This delivery remains available for review, but no further resend can be queued.',
            'not_eligible' => 'This delivery is not eligible for resend. Review its current state before trying again.',
            'stale_state' => 'This delivery changed before the resend was queued. Reload the page and review its current state.',
            'security' => 'The security request could not be verified. Reload the page and try again.',
            'not_found' => 'The requested delivery could not be found. It may have been removed or is no longer available.',
            'resend_failed' => 'The resend could not be queued. No delivery state was changed.',
            default => null,
        };
    }

    private function noticeVariant(mixed $notice): string
    {
        if ($notice === 'resend_queued') {
            return 'success';
        }
        if (in_array($notice, ['security', 'resend_failed'], true)) {
            return 'danger';
        }

        return 'warning';
    }

    /** @param array<string, mixed> $parameters */
    private function renderPage(Request $request, string $template, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $sessionId = (string) $request->cookies->get(self::SESSION_COOKIE);
        $csrfToken = $this->csrfToken($request, $sessionId);
        $response = $this->render($template, array_merge([
            'csrfToken' => $csrfToken,
            'currentRoute' => 'spoke_admin_delivery',
            'theme' => PortalPreferences::theme($request->cookies->get(PortalPreferences::THEME_COOKIE)),
            'sidebarState' => PortalPreferences::sidebarState($request->cookies->get(PortalPreferences::SIDEBAR_COOKIE)),
            'websiteName' => 'Local Spoke',
            'administratorName' => 'admin',
            'navItems' => array_values(PortalNavigation::destinations()),
            'pageTitle' => 'Delivery',
            'pageDescription' => 'Review delivery state, attempt history, and permitted resends.',
        ], $parameters), new Response('', $status));
        if ($request->cookies->get(self::CSRF_COOKIE) !== $csrfToken) {
            $response->headers->setCookie(Cookie::create(self::CSRF_COOKIE, $csrfToken, 0, '/formvex', null, true, true, false, Cookie::SAMESITE_LAX));
        }

        return $response;
    }

    private function csrfToken(Request $request, string $sessionId): string
    {
        $token = $request->cookies->get(self::CSRF_COOKIE);

        return is_string($token) && $token !== '' ? $token : $this->administratorService->refreshCsrfToken($this->runtimeConfiguration->applicationRoot, $sessionId);
    }
}
