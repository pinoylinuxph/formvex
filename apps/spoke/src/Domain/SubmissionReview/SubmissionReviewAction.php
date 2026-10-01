<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\SubmissionReview;

enum SubmissionReviewAction: string
{
    case HANDLED = 'handled';
    case NOT_SPAM = 'not_spam';
    case TRASH = 'trash';
    case RESTORE = 'restore';
    case DELETE = 'delete';
}
