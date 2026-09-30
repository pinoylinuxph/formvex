<?php

declare(strict_types=1);

namespace Formvex\Core\Delivery;

enum DeliveryOutcomeType: string
{
    case ACCEPTED = 'accepted';
    case TEMPORARY = 'temporary_failure';
    case PERMANENT = 'permanent_failure';
    case UNCERTAIN = 'uncertain';
}
