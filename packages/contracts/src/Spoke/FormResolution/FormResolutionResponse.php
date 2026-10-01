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
    ) {
    }

    /** @return array{schema_version: int, public_form_id: string, configuration_version: int, form_marker: string, captcha: array{enabled: bool, provider: string, site_key: string}, branding: array{brand_name: string}} */
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
        ];
    }
}
