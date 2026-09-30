<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

use InvalidArgumentException;

final readonly class DeliveryMessageSnapshot
{
    /** @param list<DeliveryFieldSnapshot> $fields */
    public function __construct(
        public string $senderEmail,
        public ?string $senderName,
        public string $recipient,
        public string $subject,
        public string $classification,
        public array $fields,
        public ?string $replyTo = null,
    ) {
        if ($this->senderEmail !== '' && filter_var($this->senderEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The delivery sender address is invalid.');
        }

        if (filter_var($this->recipient, FILTER_VALIDATE_EMAIL) === false || $this->subject === '' || mb_strlen($this->subject, 'UTF-8') > 200) {
            throw new InvalidArgumentException('The delivery envelope is invalid.');
        }

        if ($this->replyTo !== null && filter_var($this->replyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The delivery Reply-To address is invalid.');
        }

        if ($this->senderName !== null && mb_strlen($this->senderName, 'UTF-8') > 120) {
            throw new InvalidArgumentException('The delivery sender name is invalid.');
        }

        if (count($this->fields) > 100) {
            throw new InvalidArgumentException('The delivery field snapshot is invalid.');
        }

    }

    /** @return array{sender_email: string, sender_name: string|null, recipient: string, subject: string, classification: string, fields: list<array{label: string, value: string|list<string>}>, reply_to: string|null} */
    public function toArray(): array
    {
        return [
            'sender_email' => $this->senderEmail,
            'sender_name' => $this->senderName,
            'recipient' => $this->recipient,
            'subject' => $this->subject,
            'classification' => $this->classification,
            'fields' => array_map(static fn (DeliveryFieldSnapshot $field): array => $field->toArray(), $this->fields),
            'reply_to' => $this->replyTo,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawFields = $data['fields'] ?? null;

        if (!is_array($rawFields) || !array_is_list($rawFields)) {
            throw new InvalidArgumentException('The delivery field snapshot is invalid.');
        }

        $fields = [];

        foreach ($rawFields as $rawField) {
            if (!is_array($rawField) || array_is_list($rawField) || !is_string($rawField['label'] ?? null) || (!is_string($rawField['value'] ?? null) && !is_array($rawField['value'] ?? null))) {
                throw new InvalidArgumentException('The delivery field snapshot is invalid.');
            }

            $value = $rawField['value'];
            if (is_array($value)) {
                $normalizedValue = [];
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        throw new InvalidArgumentException('The delivery field snapshot is invalid.');
                    }
                    $normalizedValue[] = $item;
                }
                $value = $normalizedValue;
            }

            $fields[] = new DeliveryFieldSnapshot($rawField['label'], $value);
        }

        $senderName = $data['sender_name'] ?? null;
        $replyTo = $data['reply_to'] ?? null;

        if (($senderName !== null && !is_string($senderName)) || ($replyTo !== null && !is_string($replyTo))) {
            throw new InvalidArgumentException('The delivery envelope is invalid.');
        }

        return new self(
            is_string($data['sender_email'] ?? null) ? $data['sender_email'] : '',
            $senderName,
            is_string($data['recipient'] ?? null) ? $data['recipient'] : '',
            is_string($data['subject'] ?? null) ? $data['subject'] : '',
            is_string($data['classification'] ?? null) ? $data['classification'] : '',
            $fields,
            $replyTo,
        );
    }
}
