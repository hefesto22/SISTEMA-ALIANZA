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
 * Cliente: código automático, todo en MAYÚSCULAS y sin duplicados: sin RTN
 * el nombre no se repite; con RTN solo en otro municipio; el mismo RTN puede
 * llevar otro nombre.
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
            'nombre'       => '  mauricio cruz ',
            'direccion'    => 'barrio el centro, frente a la iglesia',
            'municipio_id' => $this->municipio->id,
            'bodega_id'    => $this->bodega->id,
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

test('sin RTN el nombre no se puede repetir, aunque cambien tildes, mayúsculas o espacios', function (): void {
    Cliente::factory()->create([
        'nombre'       => 'PULPERÍA ANA',
        'municipio_id' => $this->municipio->id,
        'bodega_id'    => $this->bodega->id,
    ]);

    $this->actingAs($this->admin);

    // Otro municipio, pero sin RTN: no se sabe si es el mismo negocio.
    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'       => '  pulperia   ana. ',
            'municipio_id' => $this->otroMunicipio->id,
            'bodega_id'    => $this->bodega->id,
        ])
        ->assertHasActionErrors(['nombre']);

    // Con RTN y en otro lugar sí.
    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'       => 'pulpería ana',
            'rtn'          => '08019985012345',
            'municipio_id' => $this->otroMunicipio->id,
            'bodega_id'    => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    expect(Cliente::query()->where('nombre_clave', 'PULPERIA ANA')->count())->toBe(2);
});

test('con RTN el nombre se repite solo en otro municipio, y el mismo RTN puede llevar otro nombre', function (): void {
    $existente = Cliente::factory()->create([
        'nombre'       => 'PULPERÍA ANA',
        'rtn'          => '08019985012345',
        'municipio_id' => $this->municipio->id,
        'bodega_id'    => $this->bodega->id,
    ]);

    $this->actingAs($this->admin);

    $crear = fn (array $datos) => Livewire::test(ManageClientes::class)->callAction('create', data: [
        'bodega_id' => $this->bodega->id,
        ...$datos,
    ]);

    // Sucursal en otro municipio: se permite.
    $crear(['nombre' => 'pulpería ana', 'rtn' => '08019985012345', 'municipio_id' => $this->otroMunicipio->id])
        ->assertHasNoActionErrors();

    // Mismo municipio, aunque sea otro RTN: hay que distinguirlo.
    $crear(['nombre' => 'pulpería ana', 'rtn' => '05019990001234', 'municipio_id' => $this->municipio->id])
        ->assertHasActionErrors(['nombre']);

    // Mismo RTN con otro nombre, en el mismo municipio: se permite.
    $crear(['nombre' => 'pulpería ana barrio el centro', 'rtn' => '08019985012345', 'municipio_id' => $this->municipio->id])
        ->assertHasNoActionErrors();

    // Editarlo a él mismo no choca consigo.
    Livewire::test(ManageClientes::class)
        ->callTableAction('edit', $existente, data: ['telefono' => '99887766'])
        ->assertHasNoTableActionErrors();

    expect(Cliente::query()->where('nombre', 'PULPERÍA ANA')->count())->toBe(2)
        ->and(Cliente::query()->where('rtn', '08019985012345')->count())->toBe(3)
        ->and($existente->fresh()?->telefono)->toBe('99887766');
});

test('los repetidos de antes se pueden editar mientras no se les cambie el nombre, el RTN ni el municipio', function (): void {
    $primero = Cliente::factory()->create(['nombre' => 'PULPERÍA ANA', 'municipio_id' => $this->municipio->id, 'bodega_id' => $this->bodega->id]);
    $repetido = Cliente::factory()->create(['nombre' => 'Pulperia Ana', 'municipio_id' => $this->otroMunicipio->id, 'bodega_id' => $this->bodega->id]);

    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->callTableAction('edit', $repetido, data: ['telefono' => '99887766'])
        ->assertHasNoTableActionErrors();

    Livewire::test(ManageClientes::class)
        ->callTableAction('edit', $repetido, data: ['municipio_id' => $this->municipio->id])
        ->assertHasTableActionErrors(['nombre']);

    expect($repetido->fresh()?->telefono)->toBe('99887766')
        ->and($repetido->fresh()?->municipio_id)->toBe($this->otroMunicipio->id)
        ->and($primero->nombre_clave)->toBe('PULPERIA ANA');
});

test('al escribir un nombre que ya existe avisa antes de guardar', function (): void {
    Cliente::factory()->create(['nombre' => 'PULPERÍA ANA', 'municipio_id' => $this->municipio->id, 'bodega_id' => $this->bodega->id]);

    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->assertDontSee('Ya hay un cliente con este nombre')
        ->fillForm(['nombre' => 'pulperia ana'])
        ->assertSee('Ya hay un cliente con este nombre')
        ->fillForm(['nombre' => 'pulpería ana barrio el centro'])
        ->assertDontSee('Ya hay un cliente con este nombre');
});

test('un cliente nuevo no muestra el interruptor de activo: entra activo', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->assertFormFieldHidden('activo');

    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'       => 'mini súper el ahorro',
            'municipio_id' => $this->municipio->id,
            'bodega_id'    => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    expect(Cliente::query()->where('nombre', 'MINI SÚPER EL AHORRO')->sole()->activo)->toBeTrue();
});
