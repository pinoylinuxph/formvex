<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

use RuntimeException;

final class DeliveryMessageComposer
{
    public function compose(DeliveryMessageSnapshot $snapshot): DeliveryMessage
    {
        if ($snapshot->senderEmail === '') {
            throw new RuntimeException('The delivery sender is not configured.');
        }

        $spam = $snapshot->classification === 'suspected_spam';
        $subject = $spam
            ? '[Suspected spam] ' . (str_starts_with($snapshot->subject, '[Suspected spam] ') ? substr($snapshot->subject, 17) : $snapshot->subject)
            : $snapshot->subject;
        $textParts = [];
        $htmlParts = [];

        foreach ($snapshot->fields as $field) {
            $textValue = $this->textValue($field->value);
            $htmlValue = nl2br(htmlspecialchars($textValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            $textParts[] = $field->label . ': ' . $textValue;
            $htmlParts[] = '<p><strong>' . htmlspecialchars($field->label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong><br>' . $htmlValue . '</p>';
        }

        $footer = $spam ? "\n\nClassification: Suspected spam." : '';
        $headers = $spam ? ['X-Formvex-Spam' => 'yes'] : [];

        return new DeliveryMessage(
            $snapshot->senderEmail,
            $snapshot->senderName,
            $snapshot->recipient,
            $subject,
            '<!doctype html><html><body>' . implode('', $htmlParts) . ($spam ? '<p><em>Classification: Suspected spam.</em></p>' : '') . '</body></html>',
            implode("\n", $textParts) . $footer,
            $snapshot->replyTo,
            $headers,
        );
    }

    /** @param string|list<string> $value */
    private function textValue(string|array $value): string
    {
        return is_array($value) ? implode(', ', $value) : $value;
    }
}
