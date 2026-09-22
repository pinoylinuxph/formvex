<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin;

use Formvex\Spoke\Admin\Portal\ThemePreferenceRequest;
use Formvex\Spoke\Domain\Administration\Exception\AdministratorFailure;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationRequestResolver
{
    public function login(Request $request): LoginRequest
    {
        $payload = $this->payload($request, ['login_identifier', 'password', '_token']);

        return new LoginRequest(
            $this->stringValue($payload, 'login_identifier'),
            $this->stringValue($payload, 'password'),
            $this->stringValue($payload, '_token'),
        );
    }

    public function passwordChange(Request $request): PasswordChangeRequest
    {
        $payload = $this->payload($request, ['new_password', 'confirmation', '_token']);

        return new PasswordChangeRequest(
            $this->stringValue($payload, 'new_password'),
            $this->stringValue($payload, 'confirmation'),
            $this->stringValue($payload, '_token'),
        );
    }

    public function csrf(Request $request): string
    {
        $payload = $this->payload($request, ['_token']);

        return $this->stringValue($payload, '_token');
    }

    public function themePreference(Request $request): ThemePreferenceRequest
    {
        $payload = $this->payload($request, ['_token', 'theme', 'return_route']);

        return new ThemePreferenceRequest(
            $this->stringValue($payload, '_token'),
            $this->stringValue($payload, 'theme'),
            $this->stringValue($payload, 'return_route'),
        );
    }

    /**
     * @param list<string> $allowedKeys
     * @return array<mixed, mixed>
     */
    private function payload(Request $request, array $allowedKeys): array
    {
        $payload = $request->request->all();

        foreach (array_keys($payload) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                throw new AdministratorFailure('request_malformed');
            }
        }

        return $payload;
    }

    /**
     * @param array<mixed, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value)) {
            throw new AdministratorFailure('request_malformed');
        }

        return $value;
    }
}
