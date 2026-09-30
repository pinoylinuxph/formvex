<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

final readonly class DeliveryMessage
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $senderEmail,
        public ?string $senderName,
        public string $recipient,
        public string $subject,
        public string $htmlBody,
        public string $textBody,
        public ?string $replyTo,
        public array $headers = [],
    ) {
    }
}
