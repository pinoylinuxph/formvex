<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration;

use InvalidArgumentException;

final readonly class PasswordPolicy
{
    public const MINIMUM_CHARACTERS = 12;

    public const MAXIMUM_BYTES = 72;

    public function validate(string $password): void
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            throw new InvalidArgumentException('The password must be valid UTF-8.');
        }

        $characters = mb_strlen($password, 'UTF-8');

        if ($characters < self::MINIMUM_CHARACTERS) {
            throw new InvalidArgumentException('The password must contain at least 12 characters.');
        }

        if (strlen($password) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('The password is too long.');
        }
    }
}
