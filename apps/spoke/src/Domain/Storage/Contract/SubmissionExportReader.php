<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Storage\Contract;

use Formvex\Spoke\Domain\Installation\PrivateStoragePaths;
use Formvex\Spoke\Domain\Storage\SubmissionExportData;
use Formvex\Spoke\Domain\SubmissionReview\SubmissionReviewQuery;

interface SubmissionExportReader
{
    public function read(PrivateStoragePaths $paths, SubmissionReviewQuery $query, int $limit): SubmissionExportData;
}
