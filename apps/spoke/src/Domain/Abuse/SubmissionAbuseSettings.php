<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final readonly class SubmissionAbuseSettings
{
    /** @param list<string> $trustedProxyCidrs */
    public function __construct(
        public int $perFormShortLimit = 5,
        public int $perFormShortWindowSeconds = 600,
        public int $perFormHourLimit = 20,
        public int $perFormHourWindowSeconds = 3600,
        public int $installationHourLimit = 30,
        public int $installationHourWindowSeconds = 3600,
        public int $floodMinuteLimit = 60,
        public int $floodMinuteWindowSeconds = 60,
        public int $floodHourLimit = 300,
        public int $floodHourWindowSeconds = 3600,
        public array $trustedProxyCidrs = [],
    ) {
        self::bounded($this->perFormShortLimit, 1, 1000, 'per-form short-window limit');
        self::bounded($this->perFormShortWindowSeconds, 60, 86400, 'per-form short-window length');
        self::bounded($this->perFormHourLimit, 1, 10000, 'per-form hourly limit');
        self::bounded($this->perFormHourWindowSeconds, 60, 172800, 'per-form hourly-window length');
        self::bounded($this->installationHourLimit, 1, 10000, 'installation hourly limit');
        self::bounded($this->installationHourWindowSeconds, 60, 172800, 'installation hourly-window length');
        self::bounded($this->floodMinuteLimit, 10, 10000, 'flood minute limit');
        self::bounded($this->floodMinuteWindowSeconds, 10, 3600, 'flood minute-window length');
        self::bounded($this->floodHourLimit, 10, 100000, 'flood hourly limit');
        self::bounded($this->floodHourWindowSeconds, 60, 172800, 'flood hourly-window length');

        if (count($this->trustedProxyCidrs) > 50) {
            throw new InstallationSettingsFailure('trusted_proxy_cidrs_invalid', 'Enter no more than 50 trusted proxy CIDR entries.');
        }

        foreach ($this->trustedProxyCidrs as $cidr) {
            $parts = explode('/', $cidr, 2);
            $network = $parts[0];
            $prefix = count($parts) === 2 ? $parts[1] : '';
            $packedNetwork = inet_pton($network);

            if (strlen($cidr) > 64 || filter_var($network, FILTER_VALIDATE_IP) === false || $packedNetwork === false || !ctype_digit($prefix) || (int) $prefix > strlen($packedNetwork) * 8) {
                throw new InstallationSettingsFailure('trusted_proxy_cidrs_invalid', 'Each trusted proxy entry must be one valid IPv4 or IPv6 CIDR, such as 203.0.113.0/24 or 2001:db8::/32.');
            }
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** @return list<string> */
    public function normalizedTrustedProxyCidrs(): array
    {
        $cidrs = array_values(array_unique(array_map(static fn (string $cidr): string => strtolower(trim($cidr)), $this->trustedProxyCidrs)));
        sort($cidrs);

        return $cidrs;
    }

    private static function bounded(int $value, int $minimum, int $maximum, string $label): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new InstallationSettingsFailure('abuse_setting_invalid', 'The ' . $label . ' must be between ' . $minimum . ' and ' . $maximum . '.');
        }
    }
}
