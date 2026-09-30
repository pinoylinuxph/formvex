<?php

declare(strict_types=1);

namespace Formvex\Tests\Unit\Spoke;

use Formvex\Spoke\Http\Submission\TrustedProxyIpResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyIpResolverTest extends TestCase
{
    public function testDirectConnectionIsUsedWhenProxyIsNotTrusted(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ]);

        self::assertSame('198.51.100.10', new TrustedProxyIpResolver()->resolve($request, []));
    }

    public function testForwardedClientIsUsedOnlyFromConfiguredProxy(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 192.0.2.10',
        ]);

        self::assertSame('203.0.113.10', new TrustedProxyIpResolver()->resolve($request, ['192.0.2.0/24']));
    }

    public function testIpv6ForwardedClientIsSupported(): void
    {
        $request = Request::create('/', 'POST', [], [], [], [
            'REMOTE_ADDR' => '2001:db8:ffff::10',
            'HTTP_FORWARDED' => 'for="[2001:db8::20]";proto=https',
        ]);

        self::assertSame('2001:db8::20', new TrustedProxyIpResolver()->resolve($request, ['2001:db8:ffff::/48']));
    }
}
