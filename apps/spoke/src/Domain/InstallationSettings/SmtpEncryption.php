<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

use Formvex\Spoke\Domain\InstallationSettings\Exception\InstallationSettingsFailure;

enum SmtpEncryption: string
{
    case SMTPS = 'smtps';
    case STARTTLS = 'starttls';

    public function defaultPort(): int
    {
        return $this === self::SMTPS ? 465 : 587;
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InstallationSettingsFailure(
                'smtp_encryption_invalid',
                'Choose SMTPS or STARTTLS for the SMTP encryption mode.',
                ['smtp_encryption' => 'Choose SMTPS or STARTTLS.'],
            );
    }
}
