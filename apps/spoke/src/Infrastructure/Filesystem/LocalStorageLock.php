<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Filesystem;

use Formvex\Spoke\Domain\Installation\Contract\StorageLock;

final class LocalStorageLock implements StorageLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    public function __construct($handle, private readonly string $path)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        @unlink($this->path);
        $this->handle = null;
    }
}
