<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Forms;

use Formvex\Spoke\Domain\FormConfiguration\FormConfigurationDraftData;

final readonly class FormDraftRequest
{
    public function __construct(
        public FormConfigurationDraftData $data,
        public ?int $revision,
        public string $csrfToken,
    ) {
    }
}
