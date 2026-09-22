<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Persistence;

use PDO;

interface Migration
{
    public function version(): string;

    public function up(PDO $connection): void;
}
