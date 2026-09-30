<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Abuse;

final readonly class CaptchaVerificationResult
{
    public const PASSED = 'passed';
    public const INVALID = 'invalid';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public string $reasonCode,
    ) {
    }

    public static function passed(): self
    {
        return new self(self::PASSED, 'verified');
    }

    public static function invalid(string $reasonCode = 'token_rejected'): self
    {
        return new self(self::INVALID, $reasonCode);
    }

    public static function unavailable(string $reasonCode = 'provider_unavailable'): self
    {
        return new self(self::UNAVAILABLE, $reasonCode);
    }
}
