<?php

namespace App\Support;

class PermissionCatalog
{
    public static function groups(): array
    {
        return [
            [
                'name' => 'Centro de mando',
                'permissions' => [
                    ['key' => 'dashboard.view', 'label' => 'Consultar indicadores y decisiones'],
                ],
            ],
            [
                'name' => 'Gestión territorial',
                'permissions' => [
                    ['key' => 'territorial.view', 'label' => 'Consultar personas y red'],
                    ['key' => 'territorial.manage', 'label' => 'Crear y editar personas'],
                    ['key' => 'territorial.verify', 'label' => 'Verificar personas y consentimientos'],
                    ['key' => 'territorial.delete', 'label' => 'Retirar personas'],
                    ['key' => 'territorial.tokens.manage', 'label' => 'Promover nodos y administrar enlaces de referidos'],
                ],
            ],
            [
                'name' => 'Agenda',
                'permissions' => [
                    ['key' => 'meetings.view', 'label' => 'Consultar agenda'],
                    ['key' => 'meetings.create', 'label' => 'Solicitar reuniones'],
                    ['key' => 'meetings.manage', 'label' => 'Editar reuniones'],
                    ['key' => 'meetings.approve', 'label' => 'Aprobar y rechazar reuniones'],
                    ['key' => 'meetings.delete', 'label' => 'Eliminar reuniones'],
                ],
            ],
            [
                'name' => 'Inventario',
                'permissions' => [
                    ['key' => 'inventory.view', 'label' => 'Consultar inventario'],
                    ['key' => 'inventory.manage', 'label' => 'Crear, editar y mover existencias'],
                    ['key' => 'inventory.delete', 'label' => 'Eliminar recursos'],
                ],
            ],
            [
                'name' => 'Administración',
                'permissions' => [
                    ['key' => 'users.view', 'label' => 'Consultar usuarios'],
                    ['key' => 'users.manage', 'label' => 'Crear, editar y activar usuarios'],
                    ['key' => 'users.delete', 'label' => 'Retirar usuarios de la campaña'],
                    ['key' => 'roles.view', 'label' => 'Consultar roles y permisos'],
                    ['key' => 'roles.manage', 'label' => 'Crear y editar roles'],
                    ['key' => 'roles.delete', 'label' => 'Eliminar roles sin asignaciones'],
                    ['key' => 'audit.view', 'label' => 'Consultar auditoría'],
                    ['key' => 'audit.export', 'label' => 'Exportar auditoría'],
                    ['key' => 'campaign.settings.manage', 'label' => 'Configurar la operación de la campaña'],
                ],
            ],
            [
                'name' => 'Conducción',
                'permissions' => [
                    ['key' => 'driver.routes.view', 'label' => 'Consultar traslados y abrir rutas'],
                ],
            ],
            [
                'name' => 'Analítica e integraciones',
                'permissions' => [
                    ['key' => 'analytics.view', 'label' => 'Consultar analítica avanzada'],
                    ['key' => 'integrations.manage', 'label' => 'Administrar integraciones'],
                    ['key' => 'calendar.connections.manage', 'label' => 'Vincular y desconectar calendarios'],
                    ['key' => 'calendar.changes.review', 'label' => 'Revisar cambios provenientes de Google'],
                    ['key' => 'calendar.sync.view', 'label' => 'Consultar estado y errores de sincronización'],
                    ['key' => 'campaigns.manage', 'label' => 'Configurar campañas'],
                ],
            ],
        ];
    }

    public static function keys(): array
    {
        return collect(self::groups())
            ->flatMap(fn (array $group) => $group['permissions'])
            ->pluck('key')
            ->all();
    }
}
