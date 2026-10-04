<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\InstallationSettings;

final readonly class SmtpTestResult
{
    public function __construct(
        public SmtpTestStatus $status,
        public string $failureCode,
        public string $summary,
    ) {
    }

    public static function passed(): self
    {
        return new self(SmtpTestStatus::PASSED, 'smtp_test_passed', 'The SMTP server accepted the synthetic test message. It does not contain visitor data.');
    }

    public static function failed(string $failureCode, string $summary): self
    {
        return new self(SmtpTestStatus::FAILED, $failureCode, $summary);
    }

    public static function uncertain(): self
    {
        return new self(SmtpTestStatus::UNCERTAIN, 'smtp_result_uncertain', 'The connection ended after transmission may have occurred. Do not assume the message was not sent; run the test again only when you accept the possibility of a duplicate.');
    }
}
