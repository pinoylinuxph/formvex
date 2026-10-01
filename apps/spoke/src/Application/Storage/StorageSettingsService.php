<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Storage;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Formvex\Spoke\Domain\Storage\Contract\StorageSettingsRepository;
use Formvex\Spoke\Domain\Storage\Contract\StorageUsageReader;
use Formvex\Spoke\Domain\Storage\StorageSettings;
use Formvex\Spoke\Domain\Storage\StorageSnapshot;
use Throwable;

final readonly class StorageSettingsService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private StorageSettingsRepository $settingsRepository,
        private StorageUsageReader $usageReader,
        private Clock $clock,
    ) {
    }

    public function snapshot(string $applicationRoot): StorageSnapshot
    {
        $paths = $this->storageResolver->resolve($applicationRoot);
        $settings = $this->settingsRepository->get($paths);

        return new StorageSnapshot($settings, $this->usageReader->read($paths, $settings));
    }

    /** @param array<string, string> $input */
    public function save(string $applicationRoot, array $input): StorageSnapshot
    {
        $allowance = $this->integer($input, 'storage_allowance_gb', 'live-data allowance');
        $normal = $this->integer($input, 'storage_normal_warning_percent', 'normal warning threshold');
        $critical = $this->integer($input, 'storage_critical_warning_percent', 'critical warning threshold');

        try {
            $settings = new StorageSettings($allowance * StorageSettings::BYTES_PER_GIGABYTE, $normal, $critical);
        } catch (Throwable $failure) {
            $message = $failure->getMessage();
            $field = str_contains($message, 'normal') ? 'storage_normal_warning_percent' : (str_contains($message, 'critical') ? 'storage_critical_warning_percent' : 'storage_allowance_gb');
            throw new InstallationSettingsFailure('storage_settings_invalid', $message, [$field => $message]);
        }

        $paths = $this->storageResolver->resolve($applicationRoot);
        try {
            $this->settingsRepository->save($paths, $settings, $this->clock->now());
        } catch (Throwable $failure) {
            throw new InstallationSettingsFailure('storage_settings_save_failed', 'The storage settings and their required audit record could not be saved. No storage setting was changed.');
        }

        return $this->snapshot($applicationRoot);
    }

    /** @param array<string, string> $input */
    private function integer(array $input, string $key, string $label): int
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            throw new InstallationSettingsFailure($key . '_invalid', 'The ' . $label . ' must be a whole number.', [$key => 'Enter a whole number.']);
        }

        return (int) $value;
    }
}
