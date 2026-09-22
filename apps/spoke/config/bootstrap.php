<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
$environmentFile = dirname(__DIR__, 3) . '/.env.' . $environment;

if (is_file($environmentFile)) {
    new Dotenv()->usePutenv()->load($environmentFile);
}
