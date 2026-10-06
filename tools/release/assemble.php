<?php

declare(strict_types=1);

use Formvex\Tools\Release\ReleasePackageBuilder;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/ReleasePackageBuilder.php';

$options = getopt('', ['source::', 'output:', 'version:']);
$source = is_string($options['source'] ?? null) ? (string) $options['source'] : dirname(__DIR__, 2);
$output = is_string($options['output'] ?? null) ? (string) $options['output'] : dirname(__DIR__, 2) . '/release/formvex-spoke-1.1.0.zip';
$version = is_string($options['version'] ?? null) ? (string) $options['version'] : '1.1.0';

try {
    $digest = (new ReleasePackageBuilder(realpath($source) ?: $source, $output, $version))->build();
    fwrite(STDOUT, sprintf("OK release_assemble: %s (%s)\n", $output, $digest));
} catch (Throwable $failure) {
    fwrite(STDERR, "FAIL release_assemble: The release package was not published safely.\n");
    exit(1);
}
