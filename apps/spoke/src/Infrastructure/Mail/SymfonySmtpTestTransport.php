<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Mail;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpSecretStore;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpTestTransport;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Formvex\Spoke\Domain\InstallationSettings\SmtpTestResult;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

final readonly class SymfonySmtpTestTransport implements SmtpTestTransport
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private SpokeRuntimeConfiguration $runtimeConfiguration,
        private SmtpSecretStore $secretStore,
    ) {
    }

    public function send(InstallationSettings $settings, string $recipient): SmtpTestResult
    {
        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            $password = $this->secretStore->read($paths, $settings->smtpSecretSlot);
            $transport = new EsmtpTransport(
                $settings->smtpHost,
                $settings->smtpPort,
                $settings->smtpEncryption->value === 'smtps',
            );
            $transport->setUsername($settings->smtpUsername);
            $transport->setPassword($password);
            $transport->setAutoTls($settings->smtpEncryption->value === 'starttls');
            $transport->setRequireTls(true);

            if ($transport->getStream() instanceof SocketStream) {
                $transport->getStream()->setTimeout($settings->smtpTimeoutSeconds);
            }

            $sender = $settings->senderName === null || $settings->senderName === ''
                ? new Address($settings->senderEmail)
                : new Address($settings->senderEmail, $settings->senderName);
            $email = new Email()
                ->from($sender)
                ->to($recipient)
                ->subject('[SMTP Test] Configuration test')
                ->text("This is an explicit SMTP configuration test.\n\nThe SMTP server accepted this test message. It does not contain visitor data.");

            new Mailer($transport)->send($email);

            return SmtpTestResult::passed();
        } catch (TransportExceptionInterface $exception) {
            return $this->transportFailure($exception);
        } catch (Throwable) {
            return SmtpTestResult::failed(
                'smtp_transport_error',
                'The application could not complete the SMTP test. Check the SMTP host, port, encryption mode, username, password, and timeout, then try again.',
            );
        }
    }

    private function transportFailure(TransportExceptionInterface $exception): SmtpTestResult
    {
        $details = strtolower($exception->getMessage() . ' ' . $exception->getDebug());

        if (str_contains($details, 'authentication') || str_contains($details, 'auth')) {
            return SmtpTestResult::failed(
                'smtp_authentication_failed',
                'The SMTP server rejected the username or password. Verify the credentials with the mail provider and save the corrected values before testing again.',
            );
        }

        if (str_contains($details, 'certificate') || str_contains($details, 'tls') || str_contains($details, 'ssl')) {
            return SmtpTestResult::failed(
                'smtp_tls_failed',
                'TLS negotiation failed. Verify that the selected SMTPS or STARTTLS mode, port, and server certificate match the mail provider settings.',
            );
        }

        if (str_contains($details, 'recipient') || str_contains($details, 'mailbox') || str_contains($details, 'address')) {
            return SmtpTestResult::failed(
                'smtp_recipient_rejected',
                'The SMTP server rejected the test recipient. Verify that the recipient address is valid and accepted by the mail provider.',
            );
        }

        if (str_contains($details, 'timed out') || str_contains($details, 'timeout')) {
            return SmtpTestResult::failed(
                'smtp_timeout',
                'The SMTP server did not respond within the configured timeout. Verify the host, port, firewall rules, and provider availability.',
            );
        }

        if (str_contains($details, 'data') || str_contains($details, 'message')) {
            return SmtpTestResult::uncertain();
        }

        return SmtpTestResult::failed(
            'smtp_connection_failed',
            'The application could not connect to the SMTP server. Verify the host, port, encryption mode, firewall access, and provider availability.',
        );
    }
}
