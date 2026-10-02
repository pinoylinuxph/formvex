<?php

declare(strict_types=1);

use Formvex\Core\Release\ReleasePackageVerifier;
use Formvex\Core\Release\ReleaseVerificationFailure;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['archive:', 'sha256::']);
$archive = is_string($options['archive'] ?? null) ? (string) $options['archive'] : '';
$expected = is_string($options['sha256'] ?? null) ? (string) $options['sha256'] : null;

if ($archive === '') {
    fwrite(STDERR, "FAIL release_verify: Provide --archive=<release.zip>.\n");
    exit(2);
}

try {
    $result = (new ReleasePackageVerifier())->verify($archive, $expected);
    fwrite(STDOUT, sprintf("OK release_verify: version=%s schema=%s-%s files=%d\n", $result->releaseVersion, $result->schemaMinimum, $result->schemaMaximum, count($result->entries)));
} catch (ReleaseVerificationFailure $failure) {
    fwrite(STDERR, sprintf("FAIL release_verify: %s: %s\n", $failure->failureCode, $failure->getMessage()));
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "FAIL release_verify: The release package failed safe verification.\n");
    exit(1);
}
