<?php

declare(strict_types=1);

use Formvex\Spoke\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/config/bootstrap.php';

$environment = (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod');
$debug = filter_var($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
