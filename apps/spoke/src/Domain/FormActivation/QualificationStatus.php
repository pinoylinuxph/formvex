<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormActivation;

enum QualificationStatus: string
{
    case NOT_RUN = 'not_run';
    case ACCEPTED = 'accepted';
    case SENT = 'sent';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
    case STALE = 'stale';
}
