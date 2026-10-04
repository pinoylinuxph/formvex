<?php

declare(strict_types=1);

namespace Formvex\Contracts\Spoke\FormResolution;

final readonly class FormResolutionResponse
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $publicFormId,
        public int $configurationVersion,
        public string $formMarker,
        public bool $captchaEnabled = false,
        public string $captchaProvider = 'turnstile',
        public string $captchaSiteKey = '',
        public string $brandName = 'Noname',
        public string $sourceFingerprint = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'public_form_id' => $this->publicFormId,
            'configuration_version' => $this->configurationVersion,
            'form_marker' => $this->formMarker,
            'captcha' => [
                'enabled' => $this->captchaEnabled,
                'provider' => $this->captchaProvider,
                'site_key' => $this->captchaEnabled ? $this->captchaSiteKey : '',
            ],
            'branding' => [
                'brand_name' => $this->brandName,
            ],
            'source_fingerprint' => $this->sourceFingerprint,
        ];
    }
}
