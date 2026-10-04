<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Release\ReleaseMetadata;

interface ReleaseMetadataClient
{
    public function fetch(): ReleaseMetadata;
}
