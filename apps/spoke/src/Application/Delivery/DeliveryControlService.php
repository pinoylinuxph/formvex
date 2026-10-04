<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Delivery;

use DateTimeImmutable;
use Formvex\Spoke\Domain\Administration\Contract\SpokeStorageResolver;
use Formvex\Spoke\Domain\Delivery\Contract\DeliveryControlRepository;
use Formvex\Spoke\Domain\Delivery\DeliveryControlStatus;

final readonly class DeliveryControlService
{
    public function __construct(
        private SpokeStorageResolver $storageResolver,
        private DeliveryControlRepository $repository,
    ) {
    }

    public function status(string $applicationRoot): DeliveryControlStatus
    {
        return $this->repository->status($this->storageResolver->resolve($applicationRoot));
    }

    public function pause(string $applicationRoot, string $actor, DateTimeImmutable $now): DeliveryControlStatus
    {
        return $this->repository->pause($this->storageResolver->resolve($applicationRoot), $actor, $now);
    }

    public function resume(string $applicationRoot, string $actor, DateTimeImmutable $now): DeliveryControlStatus
    {
        return $this->repository->resume($this->storageResolver->resolve($applicationRoot), $actor, $now);
    }
}
