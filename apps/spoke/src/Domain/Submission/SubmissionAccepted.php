<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Submission;

final readonly class SubmissionAccepted
{
    public const ACKNOWLEDGEMENT = 'Your message has been received.';

    public function __construct(public string $receiptId)
    {
    }
}
