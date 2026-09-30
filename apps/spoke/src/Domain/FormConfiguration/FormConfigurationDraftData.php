<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\FormConfiguration;

use Formvex\Spoke\Domain\FormConfiguration\Exception\FormConfigurationFailure;

final readonly class FormConfigurationDraftData
{
    /** @param list<FormFieldDefinition> $fields */
    public function __construct(
        public string $displayName,
        public PageIdentity $page,
        public string $recipient,
        public string $subject,
        public array $fields,
        public bool $captchaEnabled = false,
        public string $captchaSiteKey = '',
    ) {
        if ($this->displayName === '' || strlen($this->displayName) > 120) {
            throw new FormConfigurationFailure('display_name_invalid', 'Enter a form name of no more than 120 characters.', ['display_name' => 'Enter a form name.']);
        }

        if (strlen($this->recipient) > 254 || strlen($this->subject) > 200) {
            throw new FormConfigurationFailure('configuration_value_too_long', 'The recipient or subject is longer than the supported limit.');
        }

        FormFieldDefinition::validateList($this->fields);

        if (strlen($this->captchaSiteKey) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $this->captchaSiteKey) === 1) {
            throw new FormConfigurationFailure('captcha_site_key_invalid', 'The Turnstile site key is invalid or too long.', ['captcha_site_key' => 'Enter a valid public Turnstile site key.']);
        }

        if ($this->captchaEnabled && $this->captchaSiteKey === '') {
            throw new FormConfigurationFailure('captcha_site_key_required', 'Enter the public Turnstile site key before enabling CAPTCHA.', ['captcha_site_key' => 'This field is required when CAPTCHA is enabled.']);
        }
    }
}
