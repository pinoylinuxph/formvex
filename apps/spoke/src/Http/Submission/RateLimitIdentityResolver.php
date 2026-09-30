<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\Submission;

use Symfony\Component\HttpFoundation\Request;

interface RateLimitIdentityResolver
{
    /** @param list<string> $trustedCidrs */
    public function resolve(Request $request, array $trustedCidrs): string;
}
