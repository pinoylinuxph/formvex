<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Mail;

use Formvex\Core\Delivery\DeliveryMessage;
use Formvex\Spoke\Domain\Delivery\Contract\MailTransport;
use Formvex\Spoke\Domain\Delivery\DeliveryTransportResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Contract\SmtpSecretStore;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

final readonly class SymfonySmtpDeliveryTransport implements MailTransport
{
    public function __construct(private SmtpSecretStore $secretStore)
    {
    }

    public function send(PrivateStoragePaths $paths, InstallationSettings $settings, DeliveryMessage $message): DeliveryTransportResult
    {
        if ($settings->smtpHost === '' || $settings->smtpUsername === '' || $settings->senderEmail === '' || $message->senderEmail === '') {
            return DeliveryTransportResult::permanent('smtp_not_configured');
        }

        try {
            $password = $this->secretStore->read($paths, $settings->smtpSecretSlot);
            $transport = new EsmtpTransport($settings->smtpHost, $settings->smtpPort, $settings->smtpEncryption->value === 'smtps');
            $transport->setUsername($settings->smtpUsername);
            $transport->setPassword($password);
            $transport->setAutoTls($settings->smtpEncryption->value === 'starttls');
            $transport->setRequireTls(true);

            if ($transport->getStream() instanceof SocketStream) {
                $transport->getStream()->setTimeout($settings->smtpTimeoutSeconds);
            }

            $sender = $message->senderName === null || $message->senderName === ''
                ? new Address($message->senderEmail)
                : new Address($message->senderEmail, $message->senderName);
            $email = new Email()
                ->from($sender)
                ->to($message->recipient)
                ->subject($message->subject)
                ->text($message->textBody)
                ->html($message->htmlBody);

            if ($message->replyTo !== null) {
                $email->replyTo($message->replyTo);
            }

            foreach ($message->headers as $name => $value) {
                $email->getHeaders()->addTextHeader($name, $value);
            }

            new Mailer($transport)->send($email);

            return DeliveryTransportResult::accepted();
        } catch (TransportExceptionInterface $exception) {
            return $this->transportFailure($exception);
        } catch (Throwable) {
            return DeliveryTransportResult::permanent('delivery_configuration_invalid');
        }
    }

    private function transportFailure(TransportExceptionInterface $exception): DeliveryTransportResult
    {
        $details = strtolower($exception->getMessage() . ' ' . $exception->getDebug());

        if (str_contains($details, 'after data') || str_contains($details, 'transmission may have started') || str_contains($details, 'connection closed after')) {
            return DeliveryTransportResult::uncertain();
        }

        if (str_contains($details, 'authentication') || str_contains($details, 'auth') || str_contains($details, 'certificate') || str_contains($details, 'tls') || str_contains($details, 'ssl') || str_contains($details, 'recipient') || str_contains($details, 'mailbox') || str_contains($details, 'address')) {
            return DeliveryTransportResult::permanent($this->safeCategory($details));
        }

        if (preg_match('/\b4\d{2}\b/', $details) === 1 || str_contains($details, 'timed out') || str_contains($details, 'timeout') || str_contains($details, 'connection')) {
            return DeliveryTransportResult::temporary('smtp_temporary_failure');
        }

        if (preg_match('/\b5\d{2}\b/', $details) === 1) {
            return DeliveryTransportResult::permanent('smtp_permanent_failure');
        }

        return DeliveryTransportResult::temporary('smtp_transport_failure');
    }

    private function safeCategory(string $details): string
    {
        return match (true) {
            str_contains($details, 'auth') => 'smtp_authentication_failed',
            str_contains($details, 'certificate'), str_contains($details, 'tls'), str_contains($details, 'ssl') => 'smtp_tls_failed',
            default => 'smtp_recipient_rejected',
        };
    }
}
