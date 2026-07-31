<?php

namespace App\Support;

final class OfficialRoleCatalog
{
    public static function definitions(): array
    {
        return [
            'technical-administrator' => [
                'name' => 'Administrador técnico',
                'level' => 100,
                'permissions' => [
                    'dashboard.view', 'territorial.view', 'territorial.manage', 'territorial.verify', 'territorial.delete', 'territorial.tokens.manage',
                    'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.approve', 'meetings.delete',
                    'inventory.view', 'inventory.manage', 'inventory.delete',
                    'users.view', 'users.manage', 'users.delete', 'roles.view',
                    'audit.view', 'audit.export', 'analytics.view', 'integrations.manage',
                    'calendar.connections.manage', 'calendar.changes.review', 'calendar.sync.view',
                    'campaign.settings.manage',
                ],
            ],
            'auditor' => [
                'name' => 'Auditor',
                'level' => 90,
                'permissions' => ['dashboard.view', 'users.view', 'roles.view', 'audit.view', 'audit.export', 'calendar.sync.view'],
            ],
            'manager' => [
                'name' => 'Gerente',
                'level' => 80,
                'permissions' => [
                    'dashboard.view', 'territorial.view', 'territorial.manage', 'territorial.verify', 'territorial.delete', 'territorial.tokens.manage',
                    'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.approve', 'meetings.delete',
                    'inventory.view', 'inventory.manage', 'inventory.delete', 'analytics.view',
                    'users.view', 'users.manage', 'roles.view',
                    'calendar.changes.review', 'calendar.sync.view', 'campaign.settings.manage',
                ],
            ],
            'agenda' => [
                'name' => 'Agenda',
                'level' => 60,
                'permissions' => [
                    'dashboard.view', 'meetings.view', 'meetings.create', 'meetings.manage', 'meetings.approve',
                    'inventory.view', 'calendar.connections.manage', 'calendar.changes.review', 'calendar.sync.view',
                ],
            ],
            'territorial-coordination' => [
                'name' => 'Coordinación territorial',
                'level' => 60,
                'permissions' => ['dashboard.view', 'territorial.view', 'territorial.manage', 'territorial.verify', 'territorial.tokens.manage', 'meetings.view', 'meetings.create'],
            ],
            'logistics-inventory' => [
                'name' => 'Logística e inventario',
                'level' => 60,
                'permissions' => ['dashboard.view', 'meetings.view', 'inventory.view', 'inventory.manage'],
            ],
            'analyst' => [
                'name' => 'Analista',
                'level' => 50,
                'permissions' => ['dashboard.view', 'territorial.view', 'meetings.view', 'inventory.view', 'analytics.view'],
            ],
            'candidate' => [
                'name' => 'Candidato',
                'level' => 40,
                'permissions' => ['dashboard.view', 'meetings.view'],
            ],
            'driver' => [
                'name' => 'Conductor',
                'level' => 20,
                'permissions' => ['driver.routes.view'],
            ],
        ];
    }
}
