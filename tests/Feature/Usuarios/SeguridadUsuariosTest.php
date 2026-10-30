<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\Rol;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Usuarios: nadie se da el rol de super admin si no lo es, nadie se quita
 * sus propios roles ni se desactiva, el sistema nunca queda sin un super
 * admin activo, y quien tiene pedidos no se elimina (se desactiva).
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->super = User::factory()->create(['name' => 'DUEÑO']);
    $this->super->assignRole(Utils::getSuperAdminName());

    $this->admin = User::factory()->create(['created_by' => $this->super->id]);
    $this->admin->assignRole(Rol::Administrador->value);

    $this->rolSuper = Role::findByName(Utils::getSuperAdminName(), 'web');
});

test('un administrador no puede dar el rol de super admin', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name'      => 'Nuevo',
            'email'     => 'nuevo@alianza.hn',
            'password'  => 'Secreta-123',
            'roles'     => [$this->rolSuper->id],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['roles']);

    expect(User::query()->where('email', 'nuevo@alianza.hn')->exists())->toBeFalse();
});

test('nadie se cambia sus propios roles ni se desactiva a sí mismo', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->assertFormFieldDisabled('roles')
        ->assertFormFieldDisabled('is_active')
        ->fillForm(['roles' => [$this->rolSuper->id], 'is_active' => false])
        ->call('save');

    expect($this->admin->fresh()?->esSuperAdmin())->toBeFalse()
        ->and($this->admin->fresh()?->is_active)->toBeTrue();
});

test('un administrador no edita ni elimina a un super admin', function (): void {
    $this->actingAs($this->admin);

    expect(UserResource::canEdit($this->super))->toBeFalse()
        ->and(UserResource::canDelete($this->super))->toBeFalse()
        ->and(UserResource::canEdit($this->admin))->toBeTrue();
});

test('el sistema nunca queda sin un super admin activo', function (): void {
    expect(fn () => $this->super->update(['is_active' => false]))
        ->toThrow(ReglaNegocioException::class)
        ->and(fn () => $this->super->delete())
        ->toThrow(ReglaNegocioException::class);

    // Con otro super admin activo, sí se puede.
    $otro = User::factory()->create();
    $otro->assignRole(Utils::getSuperAdminName());

    $this->super->update(['is_active' => false]);

    expect($this->super->fresh()?->is_active)->toBeFalse();
});

test('al desactivar a alguien se le cierra el "Recordarme"', function (): void {
    $token = $this->admin->getRememberToken();

    $this->admin->update(['is_active' => false]);

    expect($this->admin->fresh()?->getRememberToken())->not->toBe($token);
});

test('quien tiene pedidos no se elimina: se desactiva', function (): void {
    $bodega = Bodega::factory()->create();
    $vendedor = User::factory()->create(['bodega_id' => $bodega->id, 'created_by' => $this->super->id]);
    $vendedor->assignRole(Rol::Vendedor->value);
    $sinPedidos = User::factory()->create(['created_by' => $this->super->id]);

    Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    // Aun con el permiso de eliminar usuarios.
    Permission::findOrCreate('Delete:User', 'web');
    $this->super->givePermissionTo('Delete:User');

    expect(Gate::forUser($this->super)->allows('delete', $vendedor))->toBeFalse()
        ->and(Gate::forUser($this->super)->allows('delete', $sinPedidos))->toBeTrue()
        ->and(Gate::forUser($this->super)->allows('delete', $this->super))->toBeFalse();
});

test('los cambios de rol quedan en el registro de actividad', function (): void {
    $usuario = User::factory()->create(['created_by' => $this->super->id]);
    $usuario->assignRole(Rol::Vendedor->value);
    $bodega = Role::findByName(Rol::Bodega->value, 'web');

    $this->actingAs($this->super);

    Livewire::test(EditUser::class, ['record' => $usuario->getRouteKey()])
        ->fillForm(['roles' => [$bodega->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    $registro = Activity::query()->where('event', 'roles')->where('subject_id', $usuario->id)->latest('id')->first();

    expect($usuario->fresh()?->hasRole(Rol::Bodega->value))->toBeTrue()
        ->and($registro)->not->toBeNull()
        ->and($registro?->causer_id)->toBe($this->super->id)
        ->and($registro?->properties['antes'] ?? null)->toBe([Rol::Vendedor->value])
        ->and($registro?->properties['despues'] ?? null)->toBe([Rol::Bodega->value]);
});
