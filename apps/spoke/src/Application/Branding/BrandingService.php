<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Branding;

use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Branding\BrandingAsset;
use Formvex\Spoke\Domain\Branding\BrandingSettings;
use Formvex\Spoke\Domain\Branding\BrandingUpload;
use Formvex\Spoke\Domain\Branding\Contract\BrandingAssetStore;
use Formvex\Spoke\Domain\Branding\Contract\BrandingAssetValidator;
use Formvex\Spoke\Domain\Branding\Contract\BrandingSettingsStore;
use Formvex\Spoke\Domain\Branding\StagedBrandingAsset;
use Formvex\Spoke\Domain\Branding\ValidatedBrandingAsset;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;
use Throwable;

final readonly class BrandingService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private BrandingSettingsStore $settingsStore,
        private BrandingAssetValidator $assetValidator,
        private BrandingAssetStore $assetStore,
        private Clock $clock,
    ) {
    }

    public function snapshot(string $applicationRoot): BrandingSettings
    {
        return $this->settingsStore->get($this->paths($applicationRoot));
    }

    /**
     * @param array<string, string> $input
     */
    public function save(
        string $applicationRoot,
        array $input,
        ?BrandingUpload $logoUpload,
        ?BrandingUpload $faviconUpload,
        bool $removeLogo,
        bool $removeFavicon,
    ): BrandingSaveResult {
        $paths = $this->paths($applicationRoot);
        $current = $this->settingsStore->get($paths);
        $brandName = $this->text($input, 'brand_name', 'brand name', 80, false);
        $slogan = $this->text($input, 'slogan', 'company slogan', 160, true);
        $sloganVisible = $this->switchValue($input, 'show_slogan');
        $prepared = [];
        $published = [];
        $persisted = false;

        try {
            if ($logoUpload !== null) {
                $prepared['logo'] = $this->stageAndValidate($paths, $logoUpload, 'logo');
            }

            if ($faviconUpload !== null) {
                $prepared['favicon'] = $this->stageAndValidate($paths, $faviconUpload, 'favicon');
            }

            $revision = $current->assetRevision + 1;
            $logo = $removeLogo ? null : $current->logo;
            if ($logoUpload !== null) {
                if (!isset($prepared['logo'])) {
                    throw new InstallationSettingsFailure('branding_logo_upload_failed', 'The logo upload could not be prepared. Choose the file again and retry.');
                }

                $logo = $this->publish($prepared['logo'], $revision);
                $published[] = $logo;
            }

            $favicon = $removeFavicon ? null : $current->favicon;
            if ($faviconUpload !== null) {
                $favicon = $this->publish($prepared['favicon'], $revision);
                $published[] = $favicon;
            }
            $settings = $current->withValues($brandName, $slogan, $sloganVisible, $logo, $favicon, $revision);

            try {
                $now = $this->clock->now();
                $this->settingsStore->save($paths, $settings, $now);
                $persisted = true;
                $this->settingsStore->recordAudit($paths, 'spoke.branding.settings_saved', 'success', $now);
            } catch (Throwable $failure) {
                throw $failure;
            }

            $cleanupWarning = false;

            if ($this->assetChanged($current->logo, $logo)) {
                try {
                    $removed = $this->assetStore->remove($current->logo);
                } catch (Throwable) {
                    $removed = false;
                }
                if (!$removed) {
                    $cleanupWarning = true;
                }
            }

            if ($this->assetChanged($current->favicon, $favicon)) {
                try {
                    $removed = $this->assetStore->remove($current->favicon);
                } catch (Throwable) {
                    $removed = false;
                }
                if (!$removed) {
                    $cleanupWarning = true;
                }
            }

            if ($cleanupWarning) {
                $this->settingsStore->recordAudit($paths, 'spoke.branding.asset_cleanup', 'warning', $this->clock->now());
            }

            return new BrandingSaveResult($settings, $cleanupWarning);
        } catch (InstallationSettingsFailure $failure) {
            if (!$persisted) {
                $this->removePublished($published);
            }
            $this->auditFailure($paths, $failure);
            throw $failure;
        } catch (Throwable) {
            if (!$persisted) {
                $this->removePublished($published);
            }
            $this->auditFailure($paths, new InstallationSettingsFailure('branding_save_failed', 'The branding settings could not be saved. Previous branding remains active; check storage permissions and try again.'));
            throw new InstallationSettingsFailure('branding_save_failed', 'The branding settings could not be saved. Previous branding remains active; check storage permissions and try again.');
        } finally {
            foreach ($prepared as $entry) {
                $this->assetStore->cleanup($entry['staged']);
            }
        }
    }

    /** @return array{brandName: string, slogan: string, sloganVisible: bool, logoUrl: ?string, faviconUrl: ?string, brandMark: string, assetRevision: int} */
    public function viewModel(string $applicationRoot): array
    {
        try {
            $settings = $this->snapshot($applicationRoot);
        } catch (Throwable) {
            $settings = BrandingSettings::defaults();
        }

        $brandName = $settings->brandName === '' ? 'Noname' : $settings->brandName;
        $logoUrl = null;
        $faviconUrl = null;

        try {
            $logoUrl = $this->assetStore->url($settings->logo);
            $faviconUrl = $this->assetStore->url($settings->favicon);
        } catch (Throwable) {
        }

        return [
            'brandName' => $brandName,
            'slogan' => $settings->slogan,
            'sloganVisible' => $settings->sloganVisible && $settings->slogan !== '',
            'logoUrl' => $logoUrl,
            'faviconUrl' => $faviconUrl,
            'brandMark' => $this->firstCharacter($brandName),
            'assetRevision' => $settings->assetRevision,
        ];
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    /** @return array{staged: StagedBrandingAsset, validated: ValidatedBrandingAsset} */
    private function stageAndValidate(PrivateStoragePaths $paths, BrandingUpload $upload, string $type): array
    {
        $staged = $this->assetStore->stage($paths, $upload, $type);

        try {
            return ['staged' => $staged, 'validated' => $this->assetValidator->validate($staged)];
        } catch (Throwable $failure) {
            $this->assetStore->cleanup($staged);
            throw $failure;
        }
    }

    /** @param array{staged: StagedBrandingAsset, validated: ValidatedBrandingAsset} $entry */
    private function publish(array $entry, int $revision): BrandingAsset
    {
        return $this->assetStore->publish($entry['staged'], $entry['validated'], $revision);
    }

    /** @param array<string, string> $input */
    private function switchValue(array $input, string $key): bool
    {
        $value = $input[$key] ?? '0';

        if (!in_array($value, ['0', '1'], true)) {
            throw new InstallationSettingsFailure('branding_switch_invalid', 'The slogan visibility switch contains an invalid value.', [$key => 'Use the visible switch control.']);
        }

        return $value === '1';
    }

    /** @param array<string, string> $input */
    private function text(array $input, string $key, string $label, int $maximum, bool $allowEmpty): string
    {
        $value = trim($input[$key] ?? '');

        if (!$allowEmpty && $value === '') {
            throw new InstallationSettingsFailure('branding_' . $key . '_invalid', 'Enter a brand name.', [$key => 'This field is required.']);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InstallationSettingsFailure('branding_' . $key . '_invalid', 'The ' . $label . ' must be plain text no longer than ' . $maximum . ' characters.', [$key => 'Use plain text up to ' . $maximum . ' characters.']);
        }

        return $value;
    }

    private function firstCharacter(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8');
    }

    private function assetChanged(?BrandingAsset $old, ?BrandingAsset $new): bool
    {
        return ($old?->filename) !== ($new?->filename);
    }

    private function auditFailure(PrivateStoragePaths $paths, InstallationSettingsFailure $failure): void
    {
        try {
            $this->settingsStore->recordAudit($paths, 'spoke.branding.save_failed', $failure->failureCode, $this->clock->now());
        } catch (Throwable) {
        }
    }

    /** @param list<BrandingAsset> $published */
    private function removePublished(array $published): void
    {
        foreach ($published as $asset) {
            try {
                $this->assetStore->remove($asset);
            } catch (Throwable) {
            }
        }
    }
}
