<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Release\Contract;

interface ReleasePackagePublisher
{
    public function stage(string $archivePath, string $operationId, string $privateRuntime): string;

    public function publish(string $stagingPath, string $projectRoot): void;

    public function discard(string $stagingPath): void;
}
