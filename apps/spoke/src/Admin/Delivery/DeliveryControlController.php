<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Delivery;

use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\Delivery\DeliveryControlService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Delivery\DeliveryControlFailure;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeliveryControlController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly DeliveryControlService $controlService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
        private readonly Clock $clock,
    ) {
    }

    #[Route('/formvex/delivery/pause', name: 'spoke_admin_delivery_pause', methods: ['POST'])]
    public function pause(Request $request): Response
    {
        return $this->transition($request, true);
    }

    #[Route('/formvex/delivery/resume', name: 'spoke_admin_delivery_resume', methods: ['POST'])]
    public function resume(Request $request): Response
    {
        return $this->transition($request, false);
    }

    private function transition(Request $request, bool $pause): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        if ($context->mustChangePassword) {
            return $this->redirectToRoute('spoke_admin_password_change');
        }

        try {
            if (!$this->administratorService->csrfTokenMatches($context, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            if ($request->request->getString('confirm') !== '1') {
                return $this->redirectToRoute('spoke_admin_delivery', ['error' => 'Confirm the delivery state change before submitting it.']);
            }
            $unknown = array_diff(array_keys($request->request->all()), ['_token', 'confirm']);
            if ($unknown !== []) {
                throw new AdministratorFailure('request_malformed');
            }

            $status = $pause
                ? $this->controlService->pause($this->runtimeConfiguration->applicationRoot, 'admin', $this->clock->now())
                : $this->controlService->resume($this->runtimeConfiguration->applicationRoot, 'admin', $this->clock->now());

            return $this->redirectToRoute('spoke_admin_delivery', ['notice' => $status->isPaused() ? 'delivery_paused' : 'delivery_resumed']);
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_delivery', ['error' => 'The delivery control security request could not be verified. Reload Delivery and try again.']);
        } catch (DeliveryControlFailure $failure) {
            $notice = match ($failure->failureCode) {
                'already_paused' => 'delivery_already_paused',
                'already_running' => 'delivery_already_running',
                'migration_required' => 'delivery_migration_required',
                default => 'delivery_transition_failed',
            };

            return $this->redirectToRoute('spoke_admin_delivery', ['error' => $notice]);
        }
    }

    private function context(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== ''
            ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId)
            : null;
    }
}
