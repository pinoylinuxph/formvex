<?php

declare(strict_types=1);

namespace Formvex\Spoke\Admin\Portal;

final class PortalNavigation
{
    /** @var array<string, array{label: string, description: string, route: string, icon: string}> */
    private const DESTINATIONS = [
        'overview' => [
            'label' => 'Overview',
            'description' => 'A compact view of the local Spoke installation and its current state.',
            'route' => 'spoke_admin_home',
            'icon' => 'dashboard',
        ],
        'forms' => [
            'label' => 'Forms',
            'description' => 'Form discovery, field mapping, validation, and activation.',
            'route' => 'spoke_admin_forms',
            'icon' => 'forms',
        ],
        'submissions' => [
            'label' => 'Submissions',
            'description' => 'Review, classification, restoration, and permitted deletion.',
            'route' => 'spoke_admin_submissions',
            'icon' => 'submissions',
        ],
        'delivery' => [
            'label' => 'Delivery',
            'description' => 'Queue state, delivery attempts, retry state, and SMTP test results.',
            'route' => 'spoke_admin_delivery',
            'icon' => 'delivery',
        ],
        'diagnostics' => [
            'label' => 'Diagnostics',
            'description' => 'Health checks, scheduler heartbeat, and administrator-triggered tests.',
            'route' => 'spoke_admin_diagnostics',
            'icon' => 'diagnostics',
        ],
        'maintenance' => [
            'label' => 'Maintenance',
            'description' => 'Backups, restore, retention, update notices, and maintenance state.',
            'route' => 'spoke_admin_maintenance',
            'icon' => 'maintenance',
        ],
        'settings' => [
            'label' => 'Settings',
            'description' => 'Website identity, SMTP, spam controls, limits, retention, and security.',
            'route' => 'spoke_admin_settings',
            'icon' => 'settings',
        ],
    ];

    /** @return array<string, array{label: string, description: string, route: string, icon: string}> */
    public static function destinations(): array
    {
        return self::DESTINATIONS;
    }

    /** @return list<string> */
    public static function returnRoutes(): array
    {
        return array_column(self::DESTINATIONS, 'route');
    }
}
