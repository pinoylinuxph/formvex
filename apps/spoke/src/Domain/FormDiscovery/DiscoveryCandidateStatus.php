<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormDiscovery;

enum DiscoveryCandidateStatus: string
{
    case PENDING = 'pending';
    case APPLIED = 'applied';
    case DISCARDED = 'discarded';
}
