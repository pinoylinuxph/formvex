<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

final readonly class PreflightCheck
{
    private function __construct(
        public string $code,
        public PreflightCheckStatus $status,
        public string $message,
    ) {
    }

    public static function pass(string $code, string $message): self
    {
        return new self($code, PreflightCheckStatus::Pass, $message);
    }

    public static function warning(string $code, string $message): self
    {
        return new self($code, PreflightCheckStatus::Warning, $message);
    }

    public static function fail(string $code, string $message): self
    {
        return new self($code, PreflightCheckStatus::Fail, $message);
    }
}
