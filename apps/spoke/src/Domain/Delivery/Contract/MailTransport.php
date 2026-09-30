<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Delivery\Contract;

use Formvex\Core\Delivery\DeliveryMessage;
use Formvex\Spoke\Domain\Delivery\DeliveryTransportResult;
use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\InstallationSettings\InstallationSettings;

interface MailTransport
{
    public function send(PrivateStoragePaths $paths, InstallationSettings $settings, DeliveryMessage $message): DeliveryTransportResult;
}
