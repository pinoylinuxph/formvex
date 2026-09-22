<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Installation;

use InvalidArgumentException;

final readonly class InstallationConfiguration
{
    public function __construct(
        public string $applicationRoot,
        public string $webRoot,
        public ?string $publicBaseUrl,
    ) {
        self::assertValue('application root', $this->applicationRoot);
        self::assertValue('web root', $this->webRoot);

        if ($this->publicBaseUrl !== null) {
            self::assertValue('public base URL', $this->publicBaseUrl);
        }
    }

    public static function fromOperatorInput(
        string $applicationRoot,
        string $webRoot,
        ?string $publicBaseUrl,
    ): self {
        $applicationRoot = trim($applicationRoot);
        $webRoot = trim($webRoot);
        $publicBaseUrl = $publicBaseUrl === null ? null : trim($publicBaseUrl);

        return new self($applicationRoot, $webRoot, $publicBaseUrl === '' ? null : $publicBaseUrl);
    }

    private static function assertValue(string $name, string $value): void
    {
        if ($value === '' || strlen($value) > 4096 || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('The %s is invalid.', $name));
        }
    }
}
