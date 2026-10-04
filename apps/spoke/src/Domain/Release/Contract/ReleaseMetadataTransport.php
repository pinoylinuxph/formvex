<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

use Formvex\Spoke\Domain\Release\ReleaseMetadataTransportResponse;

interface ReleaseMetadataTransport
{
    public function fetch(string $metadataUrl): ReleaseMetadataTransportResponse;
}
