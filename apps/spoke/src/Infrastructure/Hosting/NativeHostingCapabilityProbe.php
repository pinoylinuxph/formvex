<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Hosting;

use Formvex\Spoke\Domain\Installation\Contract\HostingCapabilityProbe;
use Formvex\Spoke\Domain\Installation\InstallationConfiguration;
use Formvex\Spoke\Domain\Installation\PreflightCheck;
use PDO;

final class NativeHostingCapabilityProbe implements HostingCapabilityProbe
{
    /** @var list<string> */
    private const REQUIRED_EXTENSIONS = [
        'ctype',
        'iconv',
        'pdo_sqlite',
        'mbstring',
        'openssl',
        'curl',
        'sodium',
        'zip',
    ];

    public function check(InstallationConfiguration $configuration): array
    {
        $checks = [];
        $runtimeVersion = phpversion() ?: '0.0.0';
        $isSupportedVersion = version_compare($runtimeVersion, '8.4.0', '>=')
            && version_compare($runtimeVersion, '8.6.0', '<');

        $checks[] = $isSupportedVersion
            ? PreflightCheck::pass('php_version', 'The PHP runtime is supported.')
            : PreflightCheck::fail('php_version_unsupported', 'PHP 8.4 or 8.5 is required.');

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => !extension_loaded($extension),
        ));

        $checks[] = $missing === []
            ? PreflightCheck::pass('php_extensions', 'All required PHP extensions are available.')
            : PreflightCheck::fail('php_extensions_missing', 'One or more required PHP extensions are unavailable.');

        $checks[] = PHP_SAPI === 'cli'
            ? PreflightCheck::pass('php_cli', 'PHP CLI execution is available.')
            : PreflightCheck::fail('php_cli_unavailable', 'Formvex installation requires PHP CLI execution.');

        $checks[] = in_array('sqlite', PDO::getAvailableDrivers(), true)
            ? PreflightCheck::pass('sqlite_capability', 'SQLite is available through PDO.')
            : PreflightCheck::fail('sqlite_unavailable', 'The SQLite PDO driver is unavailable.');

        if ($configuration->publicBaseUrl === null) {
            $checks[] = PreflightCheck::warning(
                'https_configuration_unverified',
                'HTTPS configuration will be verified when a public base URL is supplied.',
            );
        } else {
            $checks[] = $this->isHttpsUrl($configuration->publicBaseUrl)
                ? PreflightCheck::pass('https_configuration', 'The configured public base URL uses HTTPS.')
                : PreflightCheck::fail('https_configuration_invalid', 'The public base URL must use HTTPS.');
        }

        return $checks;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }
}
