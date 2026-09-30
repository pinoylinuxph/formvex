<?php

declare(strict_types=1);

namespace Formvex\Spoke\Http\Submission;

use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyIpResolver implements RateLimitIdentityResolver
{
    /** @param list<string> $trustedCidrs */
    public function resolve(Request $request, array $trustedCidrs): string
    {
        $remoteValue = $request->server->get('REMOTE_ADDR');
        $remote = is_string($remoteValue) ? $remoteValue : '';

        if (!$this->validIp($remote) || !$this->inAnyCidr($remote, $trustedCidrs)) {
            return $this->validIp($remote) ? $remote : 'unknown';
        }

        $forwardedValue = $request->headers->get('X-Forwarded-For');
        $forwarded = is_string($forwardedValue) ? $forwardedValue : '';

        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if ($this->validIp($candidate)) {
                return $candidate;
            }
        }

        $forwardedHeaderValue = $request->headers->get('Forwarded');
        $forwardedHeader = is_string($forwardedHeaderValue) ? $forwardedHeaderValue : '';

        if (preg_match('/(?:^|,)\s*for=\s*(?:"\[(?<bracketed>[^"\]]+)\]"|"(?<quoted>[^"]+)"|(?<bare>[^;,\s]+))/i', $forwardedHeader, $matches) === 1) {
            $candidate = $this->matchedValue($matches, 'bracketed') ?? $this->matchedValue($matches, 'quoted') ?? $this->matchedValue($matches, 'bare') ?? '';

            if ($this->validIp($candidate)) {
                return $candidate;
            }
        }

        return $remote;
    }

    /** @param list<string> $cidrs */
    private function inAnyCidr(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            $parts = explode('/', $cidr, 2);
            $network = $parts[0];
            $prefix = count($parts) === 2 ? $parts[1] : '';

            if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefix)) {
                continue;
            }

            $ipBytes = inet_pton($ip);
            $networkBytes = inet_pton($network);
            $prefixLength = (int) $prefix;

            if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes) || $prefixLength < 0 || $prefixLength > strlen($ipBytes) * 8) {
                continue;
            }

            $bytes = intdiv($prefixLength, 8);
            $bits = $prefixLength % 8;

            if (substr($ipBytes, 0, $bytes) !== substr($networkBytes, 0, $bytes)) {
                continue;
            }

            if ($bits === 0 || (ord($ipBytes[$bytes]) >> (8 - $bits)) === (ord($networkBytes[$bytes]) >> (8 - $bits))) {
                return true;
            }
        }

        return false;
    }

    private function validIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /** @param array<int|string, mixed> $matches */
    private function matchedValue(array $matches, string $key): ?string
    {
        $value = $matches[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
