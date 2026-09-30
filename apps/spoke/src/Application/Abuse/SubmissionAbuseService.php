<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Abuse;

use Formvex\Contracts\V1\Submission\SubmissionRequest;
use Formvex\Spoke\Domain\Abuse\CaptchaVerificationResult;
use Formvex\Spoke\Domain\Abuse\Contract\AbuseSettingsStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaOutageStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaSecretStore;
use Formvex\Spoke\Domain\Abuse\Contract\CaptchaVerifier;
use Formvex\Spoke\Domain\Abuse\Contract\RateLimitStore;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationRecord;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Submission\Exception\SubmissionFailure;
use Formvex\Spoke\Domain\Submission\SubmissionClassification;
use Throwable;

final readonly class SubmissionAbuseService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private AbuseSettingsStore $settingsStore,
        private RateLimitStore $rateLimitStore,
        private CaptchaSecretStore $captchaSecretStore,
        private CaptchaVerifier $captchaVerifier,
        private CaptchaOutageStore $outageStore,
        private Clock $clock,
    ) {
    }

    public function assertFloodAllowed(string $applicationRoot, string $clientIp): void
    {
        try {
            $paths = $this->paths($applicationRoot);
            $result = $this->rateLimitStore->consumeFlood($paths, $this->settingsStore->get($paths), $this->identity($clientIp), $this->clock->now());
        } catch (Throwable) {
            throw new SubmissionFailure('submission_unavailable', 'Formvex could not safely apply its abuse controls. Your message was not accepted. Please try again later.');
        }

        if (!$result->allowed) {
            throw new SubmissionFailure('rate_limited', 'Too many requests were received from this connection. Wait ' . $result->retryAfterSeconds . ' seconds before trying again.', [], $result->retryAfterSeconds);
        }
    }

    public function assertAttemptAllowed(string $applicationRoot, string $clientIp, string $publicFormId): void
    {
        try {
            $paths = $this->paths($applicationRoot);
            $result = $this->rateLimitStore->consumeAttempt($paths, $this->settingsStore->get($paths), $this->identity($clientIp), $publicFormId, $this->clock->now());
        } catch (Throwable) {
            throw new SubmissionFailure('submission_unavailable', 'Formvex could not safely apply its abuse controls. Your message was not accepted. Please try again later.');
        }

        if (!$result->allowed) {
            throw new SubmissionFailure('rate_limited', 'This form has received too many recent submissions from this connection. Wait ' . $result->retryAfterSeconds . ' seconds before trying again.', [], $result->retryAfterSeconds);
        }
    }

    public function classify(string $applicationRoot, FormConfigurationRecord $configuration, SubmissionRequest $request, string $clientIp): SubmissionClassification
    {
        $paths = $this->paths($applicationRoot);

        try {
            if ($configuration->captchaEnabled) {
                if ($request->captchaToken === null || $request->captchaToken === '') {
                    throw new SubmissionFailure('captcha_required', 'Complete the security challenge before submitting this form.');
                }

                $secret = $this->captchaSecretStore->read($paths, 'a');
                $result = $this->captchaVerifier->verify($secret, $request->captchaToken, $clientIp, $configuration->page->host, 'formvex');

                if ($result->status === CaptchaVerificationResult::UNAVAILABLE) {
                    $transition = $this->outageStore->recordFailure($paths, $result->reasonCode, $this->clock->now());

                    if ($transition->event !== null) {
                        $this->settingsStore->recordAudit($paths, $transition->event, 'open', $this->clock->now());
                    }

                    throw new SubmissionFailure('captcha_unavailable', 'The form security service is temporarily unavailable. Your message was not accepted. Please try again later.');
                }

                if ($result->status !== CaptchaVerificationResult::PASSED) {
                    throw new SubmissionFailure('captcha_invalid', 'The security challenge could not be verified. Complete a new challenge and try again.');
                }

                $transition = $this->outageStore->recordSuccess($paths, $this->clock->now());

                if ($transition->event !== null) {
                    $this->settingsStore->recordAudit($paths, $transition->event, 'recovered', $this->clock->now());
                }
            }
        } catch (SubmissionFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new SubmissionFailure('submission_unavailable', 'Formvex could not safely apply its abuse controls. Your message was not accepted. Please try again later.');
        }

        return $request->honeypot !== null && trim($request->honeypot) !== ''
            ? SubmissionClassification::SUSPECTED_SPAM
            : SubmissionClassification::NORMAL;
    }

    private function paths(string $applicationRoot): PrivateStoragePaths
    {
        return $this->storageResolver->resolve($applicationRoot);
    }

    private function identity(string $clientIp): string
    {
        return hash('sha256', 'formvex-rate-identity:' . ($clientIp !== '' ? $clientIp : 'unknown'));
    }
}
