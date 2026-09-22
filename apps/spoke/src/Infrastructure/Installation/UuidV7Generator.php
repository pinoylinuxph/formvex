<?php

declare(strict_types=1);

namespace Formvex\Spoke\Infrastructure\Installation;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Installation\Contract\IdentifierGenerator;
use RuntimeException;

final class UuidV7Generator implements IdentifierGenerator
{
    public function uuidV7(DateTimeImmutable $time): string
    {
        $milliseconds = ((int) $time->format('U')) * 1000 + (int) $time->format('v');
        $timestamp = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(10));
        $hex = $timestamp . $random;

        if (strlen($hex) !== 32) {
            throw new RuntimeException('Unable to create an installation identifier.');
        }

        $bytes = hex2bin($hex);

        if ($bytes === false) {
            throw new RuntimeException('Unable to create an installation identifier.');
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
