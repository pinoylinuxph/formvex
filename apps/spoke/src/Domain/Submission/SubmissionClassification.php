<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Submission;

enum SubmissionClassification: string
{
    case NORMAL = 'normal';
    case SUSPECTED_SPAM = 'suspected_spam';
}
