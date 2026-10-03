<?php

declare(strict_types=1);

namespace Formvex\Spoke\Application\Overview;

final readonly class AdministratorOverview
{
    /**
     * @param list<OverviewCard> $cards
     * @param list<OverviewWarning> $warnings
     * @param list<OverviewStatusRow> $systemStatus
     */
    public function __construct(
        public array $cards,
        public array $warnings,
        public array $systemStatus,
        public string $snapshotLabel,
    ) {
    }
}
