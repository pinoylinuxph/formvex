<?php

declare(strict_types=1);

namespace Formvex\Spoke\Domain\Administration\Contract;

use Formvex\Spoke\Domain\Administration\PasswordPolicy;

interface PasswordPolicyProvider
{
    public function policy(): PasswordPolicy;
}
