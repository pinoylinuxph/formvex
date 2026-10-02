<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Delivery;

use DateInterval;
use Formvex\Core\Delivery\DeliveryMessage;
use Formvex\Core\Delivery\DeliveryMessageComposer;
use Formvex\Core\Delivery\DeliveryOutcomeType;
use Formvex\Core\Delivery\DeliveryRetryPolicy;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Backup\Contract\RecoveryHoldStore;
use Formvex\Spoke\Domain\Delivery\ClaimedDeliveryJob;
use Formvex\Spoke\Domain\Delivery\ClaimedOperationalAlert;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryJobRepository;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryPacingStore;
use Formvex\Spoke\Domain\Delivery\Contract\MailTransport;
use Formvex\Spoke\Domain\Delivery\Contract\OperationalAlertRepository;
use Formvex\Spoke\Domain\Delivery\Contract\WorkerHeartbeatStore;
use Formvex\Spoke\Domain\Delivery\DeliveryWorkerResult;
use Formvex\Spoke\Domain\Installation\Contract\Clock;
use Formvex\Spoke\Domain\InstallationSettings\Contract\InstallationSettingsStore;
use Formvex\Spoke\Domain\Release\Contract\UpgradeInFlightTracker;
use Formvex\Spoke\Domain\Release\Contract\UpgradeMaintenanceStore;
use Formvex\Spoke\Infrastructure\Installation\SpokeRuntimeConfiguration;
use Throwable;

final readonly class RunDeliveryWorkerHandler
{
    private const LEASE_SECONDS = 120;
    private const MAX_BATCH = 100;

    public function __construct(
        private SpokeRuntimeConfiguration $runtimeConfiguration,
        private SpokeStorageResolver $storageResolver,
        private InstallationSettingsStore $settingsStore,
        private DeliveryJobRepository $jobs,
        private DeliveryPacingStore $pacing,
        private OperationalAlertRepository $alerts,
        private DeliveryMessageComposer $composer,
        private MailTransport $transport,
        private DeliveryRetryPolicy $retryPolicy,
        private Clock $clock,
        private ?WorkerHeartbeatStore $heartbeat = null,
        private ?RecoveryHoldStore $recoveryHoldStore = null,
        private ?UpgradeMaintenanceStore $upgradeMaintenanceStore = null,
        private ?UpgradeInFlightTracker $inFlightTracker = null,
    ) {
    }

    public function handle(RunDeliveryWorker $command): DeliveryWorkerResult
    {
        $claimed = $sent = $retried = $failed = $uncertain = $deferred = 0;
        $limit = max(1, min(self::MAX_BATCH, $command->batchLimit));
        $paths = null;
        $lease = null;

        try {
            $paths = $this->storageResolver->resolve($this->runtimeConfiguration->applicationRoot);
            if ($this->inFlightTracker !== null) {
                $lease = $this->inFlightTracker->begin($paths, 'delivery');
                if ($lease === null) {
                    return new DeliveryWorkerResult(0, 0, 0, 0, 0, 1, false);
                }
            }
            if ($this->recoveryHoldStore?->current($paths) !== null || $this->upgradeMaintenanceStore?->current($paths) !== null) {
                $this->finishLease($paths, $lease);
                $lease = null;
                return new DeliveryWorkerResult(0, 0, 0, 0, 0, 1, false);
            }
            $settings = $this->settingsStore->get($paths);

            $alertPending = $this->alerts->hasDueAlert($paths, $this->clock->now());
            if ($alertPending) {
                $now = $this->clock->now();
                if (!$this->pacing->tryConsume($paths, $now, $settings->smtpAttemptsPerMinute)) {
                    $deferred++;
                    $result = new DeliveryWorkerResult($claimed, $sent, $retried, $failed, $uncertain, $deferred);
                    $this->heartbeat?->recordSuccess($paths, $this->clock->now(), $result);
                    $this->finishLease($paths, $lease);
                    $lease = null;

                    return $result;
                }
                $alertToken = hash('sha256', bin2hex(random_bytes(16)) . $now->format('U.u'));
                $alert = $this->alerts->claimDueAlert($paths, $now, $alertToken, $now->add(new DateInterval('PT' . self::LEASE_SECONDS . 'S')));
                if ($alert !== null) {
                    $claimed++;
                    $result = $this->sendAlert($paths, $settings, $alert);
                    $nextDueAt = $this->retryPolicy->nextDueAt($alert->attemptNumber, $result->outcome, $this->clock->now());
                    if (!$this->alerts->recordOutcome($paths, $alert, $result->outcome, $result->errorCode, $nextDueAt, $this->clock->now())) {
                        $uncertain++;
                    } else {
                        match ($result->outcome) {
                            DeliveryOutcomeType::ACCEPTED => $sent++,
                            DeliveryOutcomeType::TEMPORARY => $nextDueAt === null ? $failed++ : $retried++,
                            DeliveryOutcomeType::PERMANENT => $failed++,
                            DeliveryOutcomeType::UNCERTAIN => $uncertain++,
                        };
                    }
                }
            }

            for ($index = $claimed; $index < $limit; $index++) {
                $now = $this->clock->now();

                if (!$this->pacing->tryConsume($paths, $now, $settings->smtpAttemptsPerMinute)) {
                    $deferred++;
                    break;
                }

                $leaseToken = hash('sha256', bin2hex(random_bytes(16)) . $now->format('U.u'));
                $jobs = $this->jobs->claimDueJobs($paths, $now, 1, $leaseToken, $now->add(new DateInterval('PT' . self::LEASE_SECONDS . 'S')));

                if ($jobs === []) {
                    break;
                }

                $job = $jobs[0];
                $claimed++;

                if (!$this->jobs->markTransmitting($paths, $job, $now)) {
                    $uncertain++;
                    continue;
                }

                $result = $this->send($paths, $settings, $job);
                $nextDueAt = $this->retryPolicy->nextDueAt($job->attemptNumber, $result->outcome, $this->clock->now());
                $errorCode = $result->errorCode;

                if ($result->outcome === DeliveryOutcomeType::TEMPORARY && $nextDueAt === null) {
                    $errorCode = 'retry_exhausted';
                }

                if (!$this->jobs->recordOutcome($paths, $job, $result->outcome, $errorCode, $nextDueAt, $this->clock->now())) {
                    $uncertain++;
                    continue;
                }

                match ($result->outcome) {
                    DeliveryOutcomeType::ACCEPTED => $sent++,
                    DeliveryOutcomeType::TEMPORARY => $nextDueAt === null ? $failed++ : $retried++,
                    DeliveryOutcomeType::PERMANENT => $failed++,
                    DeliveryOutcomeType::UNCERTAIN => $uncertain++,
                };
            }

            $result = new DeliveryWorkerResult($claimed, $sent, $retried, $failed, $uncertain, $deferred);
            $this->heartbeat?->recordSuccess($paths, $this->clock->now(), $result);
            $this->finishLease($paths, $lease);
            $lease = null;

            return $result;
        } catch (Throwable) {
            $result = new DeliveryWorkerResult($claimed, $sent, $retried, $failed, $uncertain, $deferred, false);
            $this->finishLease($paths, $lease);
            $lease = null;
            if ($paths !== null) {
                try {
                    $this->heartbeat?->recordFailure($paths, $this->clock->now(), $result);
                } catch (Throwable) {
                    // The original worker failure remains the authoritative result.
                }
            }

            return $result;
        }
    }

    private function finishLease(?\Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths, ?string $lease): void
    {
        if ($paths !== null && $lease !== null) {
            try {
                $this->inFlightTracker?->finish($paths, $lease);
            } catch (Throwable) {
                // The bounded drain timeout remains the safe recovery boundary for a stale lease.
            }
        }
    }

    private function send(\Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths, \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings $settings, ClaimedDeliveryJob $job): \Formvex\Spoke\Domain\Delivery\DeliveryTransportResult
    {
        if ($job->snapshot === null) {
            return \Formvex\Spoke\Domain\Delivery\DeliveryTransportResult::permanent('delivery_snapshot_invalid');
        }

        try {
            return $this->transport->send($paths, $settings, $this->composer->compose($job->snapshot));
        } catch (Throwable) {
            return \Formvex\Spoke\Domain\Delivery\DeliveryTransportResult::permanent('delivery_composition_failed');
        }
    }

    private function sendAlert(\Formvex\Spoke\Domain\Installation\PrivateStoragePaths $paths, \Formvex\Spoke\Domain\InstallationSettings\InstallationSettings $settings, ClaimedOperationalAlert $alert): \Formvex\Spoke\Domain\Delivery\DeliveryTransportResult
    {
        if ($settings->operationalAlertEmail === '' || $settings->senderEmail === '') {
            return \Formvex\Spoke\Domain\Delivery\DeliveryTransportResult::permanent('operational_alert_not_configured');
        }

        $label = match ($alert->eventCode) {
            'spoke.abuse.captcha_outage_opened' => 'CAPTCHA verification unavailable',
            'spoke.abuse.captcha_outage_reminder_due' => 'CAPTCHA verification remains unavailable',
            'spoke.abuse.captcha_outage_recovered' => 'CAPTCHA verification recovered',
            default => 'Operational state changed',
        };
        $message = new DeliveryMessage($settings->senderEmail, $settings->senderName, $settings->operationalAlertEmail, '[Formvex alert] ' . $label, '<!doctype html><html><body><p><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong></p><p>Review the local administration portal for the current operational state.</p></body></html>', $label . "\n\nReview the local administration portal for the current operational state.", null);

        return $this->transport->send($paths, $settings, $message);
    }
}
