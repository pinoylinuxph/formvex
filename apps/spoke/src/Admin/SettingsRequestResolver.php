<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Symfony\Component\HttpFoundation\Request;

final class SettingsRequestResolver
{
    /**
     * @param list<string> $allowedKeys
     * @return array<string, string>
     */
    public function payload(Request $request, array $allowedKeys): array
    {
        $payload = $request->request->all();

        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new AdministratorFailure('request_malformed');
            }
        }

        $result = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if (!is_string($value)) {
                throw new AdministratorFailure('request_malformed');
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
