<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Backup;

use Formvex\Spoke\Application\Administration\LocalAdministratorService;
use Formvex\Spoke\Application\Backup\BackupService;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Formvex\Spoke\Domain\Administration\SessionRecord;
use Formvex\Spoke\Domain\Backup\BackupFailure;
use Formvex\Spoke\Domain\Backup\BackupKind;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ValueError;

final class BackupController extends AbstractController
{
    private const SESSION_COOKIE = 'formvex_session';

    public function __construct(
        private readonly LocalAdministratorService $administratorService,
        private readonly BackupService $backupService,
        private readonly SpokeRuntimeConfiguration $runtimeConfiguration,
    ) {
    }

    #[Route('/formvex/maintenance/backups', name: 'spoke_admin_backup_create', methods: ['POST'])]
    public function create(Request $request): Response
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
            $unknown = array_diff(array_keys($request->request->all()), ['_token', 'kind']);
            if ($unknown !== []) {
                throw new AdministratorFailure('request_malformed');
            }
            $kind = BackupKind::from($request->request->getString('kind'));
            $archive = $this->backupService->create($this->runtimeConfiguration->applicationRoot, $kind);

            return $this->redirectToRoute('spoke_admin_maintenance', ['notice' => sprintf('%s backup %s was verified and is ready for download.', $archive->kind->label(), $archive->publicId)]);
        } catch (AdministratorFailure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The backup security request could not be verified. Refresh Maintenance and try again.']);
        } catch (ValueError) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'Choose either a manual backup or a pre-upgrade backup before submitting.']);
        } catch (BackupFailure $failure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => $failure->getMessage()]);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The backup could not complete safely. Check the private installation state and try again.']);
        }
    }

    #[Route('/formvex/maintenance/backups/{backupId}/download', name: 'spoke_admin_backup_download', methods: ['POST'])]
    public function download(Request $request, string $backupId): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        try {
            if (!$this->administratorService->csrfTokenMatches($context, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            $download = $this->backupService->beginDownload($this->runtimeConfiguration->applicationRoot, $backupId);
            $response = new StreamedResponse(function () use ($download, $backupId): void {
                try {
                    $handle = fopen($download['path'], 'rb');
                    if ($handle === false) {
                        return;
                    }
                    try {
                        while (!feof($handle)) {
                            $chunk = fread($handle, 1024 * 1024);
                            if ($chunk === false) {
                                break;
                            }
                            echo $chunk;
                        }
                    } finally {
                        fclose($handle);
                    }
                } finally {
                    $this->backupService->finishDownload($this->runtimeConfiguration->applicationRoot, $backupId);
                }
            });
            $response->headers->set('Content-Type', 'application/zip');
            $response->headers->set('Content-Disposition', 'attachment; filename="backup-' . preg_replace('/[^a-zA-Z0-9-]/', '', $backupId) . '.zip"');
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Length', (string) $download['archive']->sizeBytes);

            return $response;
        } catch (AdministratorFailure|BackupFailure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The selected backup could not be downloaded. Refresh Maintenance and try again.']);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The selected backup could not be streamed safely. Refresh Maintenance and try again.']);
        }
    }

    #[Route('/formvex/maintenance/backups/{backupId}/delete', name: 'spoke_admin_backup_delete', methods: ['POST'])]
    public function delete(Request $request, string $backupId): Response
    {
        $context = $this->context($request);
        if ($context === null) {
            return $this->redirectToRoute('spoke_admin_login');
        }
        try {
            if (!$this->administratorService->csrfTokenMatches($context, $request->request->getString('_token'))) {
                throw new AdministratorFailure('csrf_invalid');
            }
            if ($request->request->getString('confirm_permanent') !== '1') {
                return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'Confirm permanent deletion before deleting the selected backup.']);
            }
            $this->backupService->delete($this->runtimeConfiguration->applicationRoot, $backupId);

            return $this->redirectToRoute('spoke_admin_maintenance', ['notice' => 'The selected backup was permanently deleted.']);
        } catch (AdministratorFailure|BackupFailure) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The selected backup could not be deleted. It may be unavailable or currently downloading. Refresh Maintenance and try again.']);
        } catch (Throwable) {
            return $this->redirectToRoute('spoke_admin_maintenance', ['error' => 'The selected backup could not be deleted safely. No other backup was changed.']);
        }
    }

    private function context(Request $request): ?SessionRecord
    {
        $sessionId = $request->cookies->get(self::SESSION_COOKIE);

        return is_string($sessionId) && $sessionId !== '' ? $this->administratorService->session($this->runtimeConfiguration->applicationRoot, $sessionId) : null;
    }
}
