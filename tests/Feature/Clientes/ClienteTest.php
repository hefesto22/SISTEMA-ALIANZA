<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Municipio;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Cliente: código automático, todo en MAYÚSCULAS y sin duplicados
 * (mismo nombre y RTN solo se permite en municipios distintos).
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create();
    $this->municipio = Municipio::factory()->create();
    $this->otroMunicipio = Municipio::factory()->create(['departamento_id' => $this->municipio->departamento_id]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
});

test('el código del cliente se genera solo', function (): void {
    $cliente = Cliente::factory()->create();
    $conCodigo = Cliente::factory()->create(['codigo' => 'ANTIGUO-77']);

    expect($cliente->codigo)->toBe('CLI-'.str_pad((string) $cliente->id, 6, '0', STR_PAD_LEFT))
        ->and($cliente->fresh()?->codigo)->toBe($cliente->codigo)
        ->and($conCodigo->fresh()?->codigo)->toBe('ANTIGUO-77');
});

test('todo se guarda en mayúsculas', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'          => '  mauricio cruz ',
            'direccion'       => 'barrio el centro, frente a la iglesia',
            'departamento_id' => $this->municipio->departamento_id,
            'municipio_id'    => $this->municipio->id,
            'bodega_id'       => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    $cliente = Cliente::query()->latest('id')->firstOrFail();
    $bodega = Bodega::factory()->create(['nombre' => 'lucerna', 'direccion' => 'salida a ocotepeque']);
    $ruta = Ruta::factory()->create(['nombre' => 'ruta 1 - señor de esquipulas']);
    $producto = Producto::factory()->create(['descripcion' => 'café molido 12x100g']);
    $usuario = User::factory()->create(['name' => 'josé martínez']);

    expect($cliente->nombre)->toBe('MAURICIO CRUZ')
        ->and($cliente->direccion)->toBe('BARRIO EL CENTRO, FRENTE A LA IGLESIA')
        ->and($bodega->fresh()?->nombre)->toBe('LUCERNA')
        ->and($bodega->fresh()?->direccion)->toBe('SALIDA A OCOTEPEQUE')
        ->and($ruta->fresh()?->nombre)->toBe('RUTA 1 - SEÑOR DE ESQUIPULAS')
        ->and($producto->fresh()?->descripcion)->toBe('CAFÉ MOLIDO 12X100G')
        ->and($usuario->fresh()?->name)->toBe('JOSÉ MARTÍNEZ');
});

test('mismo nombre y RTN se puede en otro municipio, pero no en el mismo', function (): void {
    $existente = Cliente::factory()->create([
        'nombre'       => 'PULPERÍA ANA',
        'rtn'          => '08019985012345',
        'municipio_id' => $this->municipio->id,
        'bodega_id'    => $this->bodega->id,
    ]);

    $this->actingAs($this->admin);

    // Sucursal en otro municipio: se permite.
    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'          => 'pulpería ana',
            'rtn'             => '08019985012345',
            'departamento_id' => $this->otroMunicipio->departamento_id,
            'municipio_id'    => $this->otroMunicipio->id,
            'bodega_id'       => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    // Mismo municipio: es el mismo cliente.
    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'          => 'pulpería ana',
            'rtn'             => '08019985012345',
            'departamento_id' => $this->municipio->departamento_id,
            'municipio_id'    => $this->municipio->id,
            'bodega_id'       => $this->bodega->id,
        ])
        ->assertHasActionErrors(['nombre']);

    // Sin RTN no se bloquea: puede ser otro negocio con el mismo nombre.
    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'          => 'pulpería ana',
            'departamento_id' => $this->municipio->departamento_id,
            'municipio_id'    => $this->municipio->id,
            'bodega_id'       => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    // Editarlo a él mismo no choca consigo.
    Livewire::test(ManageClientes::class)
        ->callTableAction('edit', $existente, data: ['telefono' => '99887766'])
        ->assertHasNoTableActionErrors();

    expect(Cliente::query()->where('nombre', 'PULPERÍA ANA')->count())->toBe(3)
        ->and($existente->fresh()?->telefono)->toBe('99887766');
});
