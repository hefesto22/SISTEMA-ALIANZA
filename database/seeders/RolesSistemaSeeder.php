<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Rol;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles de trabajo con sus permisos (formato Shield "Accion:Modelo").
 *
 *  administrador → todo el catálogo, precios, márgenes, promociones, rutas, pedidos y usuarios.
 *  bodega        → actualiza precios, ve historial, promociones y rutas, valida despacho de su bodega.
 *  vendedor      → consulta precios y promociones vigentes, maneja los clientes de sus rutas y su preventa.
 *
 * Ojo: al correrlo de nuevo, los permisos de estos tres roles vuelven a
 * estos valores. Los cambios finos se hacen después desde Roles en el panel.
 */
class RolesSistemaSeeder extends Seeder
{
    private const TODO = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            Rol::Administrador->value => [
                ...$this->permisos('Bodega', self::TODO),
                ...$this->permisos('Ruta', ['viewAny', 'view', 'create', 'update', 'delete']),
                ...$this->permisos('Proveedor', self::TODO),
                ...$this->permisos('Categoria', ['viewAny', 'view', 'create', 'update', 'delete']),
                ...$this->permisos('Producto', self::TODO),
                ...$this->permisos('CambioPrecio', ['viewAny', 'view']),
                ...$this->permisos('Cliente', self::TODO),
                ...$this->permisos('Pedido', ['viewAny', 'view', 'create', 'update', 'delete', 'validar']),
                ...$this->permisos('Promocion', ['viewAny', 'view', 'create', 'update', 'delete']),
                ...$this->permisos('User', ['viewAny', 'view', 'create', 'update']),
            ],
            Rol::Bodega->value => [
                ...$this->permisos('Producto', ['viewAny', 'view', 'update']),
                ...$this->permisos('Proveedor', ['viewAny', 'view']),
                ...$this->permisos('Categoria', ['viewAny', 'view']),
                ...$this->permisos('CambioPrecio', ['viewAny', 'view']),
                ...$this->permisos('Cliente', ['viewAny', 'view', 'create', 'update']),
                ...$this->permisos('Pedido', ['viewAny', 'view', 'create', 'update', 'delete', 'validar']),
                ...$this->permisos('Promocion', ['viewAny', 'view']),
                ...$this->permisos('Ruta', ['viewAny', 'view']),
            ],
            Rol::Vendedor->value => [
                ...$this->permisos('Producto', ['viewAny', 'view']),
                ...$this->permisos('Cliente', ['viewAny', 'view', 'create', 'update']),
                ...$this->permisos('Pedido', ['viewAny', 'view', 'create', 'update', 'delete']),
                ...$this->permisos('Promocion', ['viewAny', 'view']),
            ],
        ];

        foreach ($roles as $nombre => $permisos) {
            foreach ($permisos as $permiso) {
                Permission::findOrCreate($permiso, 'web');
            }

            Role::findOrCreate($nombre, 'web')->syncPermissions($permisos);
        }

        // El super admin se queda con TODOS los permisos, incluidos los nuevos.
        Role::findOrCreate(Utils::getSuperAdminName(), 'web')->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✓ Roles: '.implode(', ', array_keys($roles)));
    }

    /**
     * @param list<string> $acciones
     *
     * @return list<string>
     */
    private function permisos(string $modelo, array $acciones): array
    {
        return array_map(fn (string $accion): string => ucfirst($accion).':'.$modelo, $acciones);
    }
}
