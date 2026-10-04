<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

final readonly class PublicFormResolution
{
    public function __construct(
        public string $publicFormId,
        public int $configurationVersion,
        public string $formMarker,
        public bool $captchaEnabled = false,
        public string $captchaProvider = 'turnstile',
        public string $captchaSiteKey = '',
        public string $sourceFingerprint = '',
    ) {
    }
}
