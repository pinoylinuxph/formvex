<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

final readonly class LoginThrottleSettings
{
    public function __construct(
        public int $maximumFailures = 5,
        public int $windowMinutes = 15,
        public int $cooldownMinutes = 15,
    ) {
        if ($this->maximumFailures < 3 || $this->maximumFailures > 20) {
            throw new InstallationSettingsFailure('login_throttle_invalid', 'The failed-login limit must be between 3 and 20.', ['maximum_failures' => 'Enter a value from 3 to 20.']);
        }

        if ($this->windowMinutes < 5 || $this->windowMinutes > 60) {
            throw new InstallationSettingsFailure('login_throttle_invalid', 'The login window must be between 5 and 60 minutes.', ['window_minutes' => 'Enter a value from 5 to 60 minutes.']);
        }

        if ($this->cooldownMinutes < 5 || $this->cooldownMinutes > 120) {
            throw new InstallationSettingsFailure('login_throttle_invalid', 'The login cooldown must be between 5 and 120 minutes.', ['cooldown_minutes' => 'Enter a value from 5 to 120 minutes.']);
        }
    }

    public function windowSeconds(): int
    {
        return $this->windowMinutes * 60;
    }

    public function cooldownSeconds(): int
    {
        return $this->cooldownMinutes * 60;
    }
}
