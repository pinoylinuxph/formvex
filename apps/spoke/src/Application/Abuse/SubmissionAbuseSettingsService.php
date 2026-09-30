<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Abuse;

use Formvex\Spoke\Domain\Abuse\AbuseSettingsSnapshot;
use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaOutageStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaSecretStore;
use Formvex\Spoke\Domain\Abuse\Contract\RateLimitStore;
use Formvex\Spoke\Domain\Abuse\SubmissionAbuseSettings;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final readonly class SubmissionAbuseSettingsService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private AbuseSettingsStore $settingsStore,
        private CaptchaOutageStore $outageStore,
        private RateLimitStore $rateLimitStore,
        private CaptchaSecretStore $secretStore,
        private Clock $clock,
    ) {
    }

    public function snapshot(string $applicationRoot): AbuseSettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $settings = $this->settingsStore->get($paths);

        return new AbuseSettingsSnapshot(
            $settings,
            $this->secretStore->isConfigured($paths, 'a'),
            $this->outageStore->getOutageState($paths),
        );
    }

    /** @param array<string, string> $input */
    public function save(string $applicationRoot, array $input): AbuseSettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $settings = new SubmissionAbuseSettings(
            $this->integer($input, 'per_form_short_limit', 'per-form short-window limit'),
            $current->perFormShortWindowSeconds,
            $this->integer($input, 'per_form_hour_limit', 'per-form hourly limit'),
            $current->perFormHourWindowSeconds,
            $this->integer($input, 'installation_hour_limit', 'installation hourly limit'),
            $current->installationHourWindowSeconds,
            $this->integer($input, 'flood_minute_limit', 'flood minute limit'),
            $current->floodMinuteWindowSeconds,
            $this->integer($input, 'flood_hour_limit', 'flood hourly limit'),
            $current->floodHourWindowSeconds,
            $this->cidrs($input['trusted_proxy_cidrs'] ?? ''),
        );
        $now = $this->clock->now();
        $this->settingsStore->save($paths, $settings, $now);
        $this->settingsStore->recordAudit($paths, 'spoke.abuse.settings_saved', 'success', $now);

        return $this->snapshot($applicationRoot);
    }

    public function saveTurnstileSecret(string $applicationRoot, string $secret): AbuseSettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $this->secretStore->write($paths, 'a', trim($secret));
        $this->settingsStore->recordAudit($paths, 'spoke.abuse.turnstile_secret_saved', 'success', $this->clock->now());

        return $this->snapshot($applicationRoot);
    }

    public function resetDefaults(string $applicationRoot): AbuseSettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $this->settingsStore->save($paths, SubmissionAbuseSettings::defaults(), $this->clock->now());
        $this->settingsStore->recordAudit($paths, 'spoke.abuse.settings_reset', 'success', $this->clock->now());

        return $this->snapshot($applicationRoot);
    }

    public function clearCounters(string $applicationRoot): AbuseSettingsSnapshot
    {
        $paths = $this->paths($applicationRoot);
        $this->rateLimitStore->clearCounters($paths);
        $this->settingsStore->recordAudit($paths, 'spoke.abuse.counters_cleared', 'success', $this->clock->now());

        return $this->snapshot($applicationRoot);
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    /** @param array<string, string> $input */
    private function integer(array $input, string $key, string $label): int
    {
        $value = $input[$key] ?? null;

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' must be a whole number.', [$key => 'Enter a whole number.']);
        }

        return (int) $value;
    }

    /** @return list<string> */
    private function cidrs(string $input): array
    {
        $lines = preg_split('/\R/', trim($input)) ?: [];
        $cidrs = [];

        foreach ($lines as $line) {
            $line = strtolower(trim($line));
            if ($line !== '') {
                $cidrs[] = $line;
            }
        }

        return array_values(array_unique($cidrs));
    }
}
