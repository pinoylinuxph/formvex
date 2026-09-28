<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

enum FormConfigurationState: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}
