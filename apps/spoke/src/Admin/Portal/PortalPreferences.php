<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

use Symfony\Component\HttpFoundation\Cookie;

final class PortalPreferences
{
    public const THEME_COOKIE = 'formvex_theme';

    public const SIDEBAR_COOKIE = 'formvex_sidebar';

    public static function theme(?string $value): string
    {
        return in_array($value, ['light', 'dark'], true) ? $value : 'light';
    }

    public static function sidebarState(?string $value): string
    {
        return in_array($value, ['expanded', 'collapsed'], true) ? $value : 'expanded';
    }

    public static function themeCookie(string $theme): Cookie
    {
        return Cookie::create(
            self::THEME_COOKIE,
            self::theme($theme),
            0,
            '/formvex',
            null,
            true,
            false,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
