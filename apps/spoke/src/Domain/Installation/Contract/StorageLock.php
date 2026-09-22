<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation\Contract;

interface StorageLock
{
    public function release(): void;
}
