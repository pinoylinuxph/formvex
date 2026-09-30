<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Core\Delivery;

use DateTimeImmutable;
use Formvex\Core\Delivery\DeliveryFieldSnapshot;
use Formvex\Core\Delivery\DeliveryMessageComposer;
use Formvex\Core\Delivery\DeliveryMessageSnapshot;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Core\Delivery\DeliveryRetryPolicy;
use PHPUnit\Framework\TestCase;

final class DeliveryMessageTest extends TestCase
{
    public function testComposerEscapesValuesPreservesOrderAndAddsSpamMarker(): void
    {
        $message = new DeliveryMessageComposer()->compose(new DeliveryMessageSnapshot(
            'forms@example.test',
            'Website forms',
            'owner@example.test',
            'Contact request',
            'suspected_spam',
            [
                new DeliveryFieldSnapshot('Name', '<script>alert(1)</script>'),
                new DeliveryFieldSnapshot('Topics', ['Support', 'Sales']),
            ],
            'visitor@example.test',
        ));

        self::assertSame('[Suspected spam] Contact request', $message->subject);
        self::assertSame('yes', $message->headers['X-Formvex-Spam']);
        self::assertSame('visitor@example.test', $message->replyTo);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $message->htmlBody);
        self::assertStringNotContainsString('<script>', $message->htmlBody);
        self::assertStringContainsString("Name: <script>alert(1)</script>\nTopics: Support, Sales", $message->textBody);
    }

    public function testRetryPolicyUsesExactlyTheApprovedFiveDelays(): void
    {
        $now = new DateTimeImmutable('2026-09-30T10:00:00Z');
        $policy = new DeliveryRetryPolicy();
        $delays = [60, 300, 900, 3600, 21600];

        foreach ($delays as $attempt => $delay) {
            $next = $policy->nextDueAt($attempt + 1, DeliveryOutcomeType::TEMPORARY, $now);
            self::assertNotNull($next);
            self::assertSame($now->getTimestamp() + $delay, $next->getTimestamp());
        }

        self::assertNull($policy->nextDueAt(6, DeliveryOutcomeType::TEMPORARY, $now));
        self::assertNull($policy->nextDueAt(1, DeliveryOutcomeType::PERMANENT, $now));
        self::assertNull($policy->nextDueAt(1, DeliveryOutcomeType::UNCERTAIN, $now));
    }
}
