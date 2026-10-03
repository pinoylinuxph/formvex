<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Overview\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Overview\DeliveryOverviewCounts;
use Formvex\Spoke\Domain\Overview\FormOverviewCounts;
use Formvex\Spoke\Domain\Overview\SubmissionOverviewCounts;

interface OverviewSummaryReader
{
    public function forms(PrivateStoragePaths $paths): FormOverviewCounts;

    public function submissions(PrivateStoragePaths $paths): SubmissionOverviewCounts;

    public function delivery(PrivateStoragePaths $paths): DeliveryOverviewCounts;
}
