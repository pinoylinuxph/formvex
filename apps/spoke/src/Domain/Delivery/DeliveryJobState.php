<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery;

enum DeliveryJobState: string
{
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case SENT = 'sent';
    case FAILED = 'failed';
    case UNCERTAIN = 'uncertain';
}
