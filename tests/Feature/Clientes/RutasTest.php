<?php

declare(strict_types=1);

use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Bodegas\Pages\ManageBodegas;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Rutas\Pages\ManageRutas;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Departamento;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\BodegaSeeder;
use Database\Seeders\DivisionPoliticaSeeder;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Rutas: cada bodega arma sus rutas (municipios + vendedores). El vendedor
 * ve y crea solo clientes de los municipios de sus rutas; si le vende a
 * otro cliente, el pedido queda marcado fuera de ruta con el motivo.
 */
beforeEach(function (): void {
    $this->seed([RolesSistemaSeeder::class, DivisionPoliticaSeeder::class]);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create();

    $copan = Departamento::query()->where('codigo', '04')->firstOrFail();
    $this->santaRosa = $copan->municipios()->where('nombre', 'SANTA ROSA DE COPÁN')->firstOrFail();
    $this->dolores = $copan->municipios()->where('nombre', 'DOLORES')->firstOrFail();
    $this->ocotepeque = Departamento::query()->where('codigo', '14')->firstOrFail();
    $this->sinuapa = $this->ocotepeque->municipios()->where('nombre', 'SINUAPA')->firstOrFail();

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->ruta = Ruta::factory()
        ->cubre($this->santaRosa)
        ->para($this->vendedor)
        ->create(['bodega_id' => $this->bodega->id, 'nombre' => 'Ruta 1 - Santa Rosa']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->producto = Producto::factory()->create();
});

test('el catálogo trae los 18 departamentos y 298 municipios y se puede volver a correr', function (): void {
    $this->seed(DivisionPoliticaSeeder::class);

    expect(Departamento::query()->count())->toBe(18)
        ->and(Municipio::query()->count())->toBe(298)
        ->and($this->santaRosa->etiqueta())->toBe('SANTA ROSA DE COPÁN · COPÁN');
});

test('las bodegas iniciales quedan en su municipio', function (): void {
    $this->seed(BodegaSeeder::class);

    expect(Bodega::query()->where('codigo', 'COP')->firstOrFail()->municipio?->nombre)->toBe('SANTA ROSA DE COPÁN')
        ->and(Bodega::query()->where('codigo', 'LUC')->firstOrFail()->municipio?->departamento->nombre)->toBe('OCOTEPEQUE');
});

test('el vendedor solo ve los clientes de los municipios de sus rutas activas', function (): void {
    $enRuta = Cliente::factory()->create(['municipio_id' => $this->santaRosa->id, 'bodega_id' => $this->bodega->id]);
    $otroMunicipio = Cliente::factory()->create(['municipio_id' => $this->dolores->id, 'bodega_id' => $this->bodega->id]);
    $sinZona = Cliente::factory()->create(['municipio_id' => null, 'bodega_id' => $this->bodega->id]);
    $deRutaInactiva = Cliente::factory()->create(['municipio_id' => $this->sinuapa->id]);

    Ruta::factory()->inactiva()->cubre($this->sinuapa)->para($this->vendedor)->create();

    $this->actingAs($this->vendedor);

    Livewire::test(ManageClientes::class)
        ->assertCanSeeTableRecords([$enRuta])
        ->assertCanNotSeeTableRecords([$otroMunicipio, $sinZona, $deRutaInactiva]);

    expect(Cliente::query()->visiblePara($this->vendedor)->pluck('id')->all())->toBe([$enRuta->id]);
});

test('un vendedor sin rutas no ve clientes y se le dice por qué', function (): void {
    $cliente = Cliente::factory()->create(['municipio_id' => $this->santaRosa->id, 'bodega_id' => $this->bodega->id]);

    $nuevo = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $nuevo->assignRole(Rol::Vendedor->value);

    $this->actingAs($nuevo);

    Livewire::test(ManageClientes::class)
        ->assertCanNotSeeTableRecords([$cliente])
        ->assertSee('No tiene rutas asignadas');
});

test('bodega ve los clientes de su bodega y administración ve todos', function (): void {
    $deSuBodega = Cliente::factory()->create(['municipio_id' => $this->dolores->id, 'bodega_id' => $this->bodega->id]);
    $deOtraBodega = Cliente::factory()->create(['municipio_id' => $this->santaRosa->id]);

    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);

    $this->actingAs($encargado);

    Livewire::test(ManageClientes::class)
        ->assertCanSeeTableRecords([$deSuBodega])
        ->assertCanNotSeeTableRecords([$deOtraBodega]);

    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->assertCanSeeTableRecords([$deSuBodega, $deOtraBodega]);
});

test('administración crea el cliente con su municipio (un solo campo, con el departamento)', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->assertFormFieldExists('municipio_id')
        ->assertFormFieldDoesNotExist('departamento_id');

    // Se busca por municipio o por departamento: "SANTA ROSA DE COPÁN · COPÁN".
    expect(Municipio::opcionesConDepartamento([$this->santaRosa->id, $this->sinuapa->id]))->toBe([
        $this->santaRosa->id => 'SANTA ROSA DE COPÁN · COPÁN',
        $this->sinuapa->id   => 'SINUAPA · OCOTEPEQUE',
    ]);

    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'       => 'Pulpería La Bendición',
            'municipio_id' => $this->santaRosa->id,
            'bodega_id'    => $this->bodega->id,
        ])
        ->assertHasNoActionErrors();

    $cliente = Cliente::query()->where('nombre', 'PULPERÍA LA BENDICIÓN')->firstOrFail();

    expect($cliente->municipio_id)->toBe($this->santaRosa->id)
        ->and($cliente->codigo)->toBe(Cliente::codigoPara($cliente->id));
});

test('en la bodega el municipio tiene que ser del departamento escogido', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageBodegas::class)
        ->callAction('create', data: [
            'codigo'          => 'MAL',
            'nombre'          => 'Bodega Mal Ubicada',
            'departamento_id' => $this->santaRosa->departamento_id,
            'municipio_id'    => $this->sinuapa->id,
        ])
        ->assertHasActionErrors(['municipio_id']);

    expect(Bodega::query()->where('codigo', 'MAL')->exists())->toBeFalse();
});

test('el vendedor solo crea clientes en sus rutas y quedan en su bodega', function (): void {
    $this->actingAs($this->vendedor);

    // Su ruta tiene un solo municipio: ya viene escogido.
    Livewire::test(ManageClientes::class)
        ->mountAction('create')
        ->assertSchemaStateSet(['municipio_id' => $this->santaRosa->id]);

    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre'       => 'Pulpería Fuera de Ruta',
            'municipio_id' => $this->dolores->id,
        ])
        ->assertHasActionErrors(['municipio_id']);

    Livewire::test(ManageClientes::class)
        ->callAction('create', data: [
            'nombre' => 'Pulpería En Ruta',
        ])
        ->assertHasNoActionErrors();

    $cliente = Cliente::query()->where('nombre', 'PULPERÍA EN RUTA')->firstOrFail();

    expect($cliente->municipio_id)->toBe($this->santaRosa->id)
        ->and($cliente->bodega_id)->toBe($this->bodega->id)
        ->and(Cliente::query()->where('nombre', 'PULPERÍA FUERA DE RUTA')->exists())->toBeFalse();
});

test('sin marcar fuera de ruta el vendedor no puede escoger un cliente de otra ruta', function (): void {
    $fuera = Cliente::factory()->create(['municipio_id' => $this->sinuapa->id]);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'cliente_id' => $fuera->id,
            'fecha'      => now()->toDateString(),
            'items'      => [
                ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['cliente_id']);

    $undoRepeaterFake();

    expect(Pedido::query()->count())->toBe(0);
});

test('fuera de ruta: el pedido sale marcado y con el motivo, que es obligatorio', function (): void {
    $fuera = Cliente::factory()->create(['municipio_id' => $this->sinuapa->id]);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    $pedido = [
        'fuera_de_ruta' => true,
        'cliente_id'    => $fuera->id,
        'fecha'         => now()->toDateString(),
        'items'         => [
            ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
        ],
    ];

    Livewire::test(CreatePedido::class)
        ->fillForm($pedido)
        ->call('create')
        ->assertHasFormErrors(['motivo_fuera_ruta']);

    Livewire::test(CreatePedido::class)
        ->fillForm([...$pedido, 'motivo_fuera_ruta' => 'Cubriendo la ruta de Ocotepeque'])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $creado = Pedido::query()->latest('id')->firstOrFail();

    expect($creado->fuera_de_ruta)->toBeTrue()
        ->and($creado->motivo_fuera_ruta)->toBe('CUBRIENDO LA RUTA DE OCOTEPEQUE')
        ->and($creado->bodega_id)->toBe($this->bodega->id);
});

test('si el cliente sí es de su ruta el pedido no queda fuera de ruta aunque lo marque', function (): void {
    $cliente = Cliente::factory()->create(['municipio_id' => $this->santaRosa->id, 'bodega_id' => $this->bodega->id]);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'fuera_de_ruta'     => true,
            'cliente_id'        => $cliente->id,
            'motivo_fuera_ruta' => 'Por si acaso',
            'fecha'             => now()->toDateString(),
            'items'             => [
                ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $creado = Pedido::query()->latest('id')->firstOrFail();

    expect($creado->fuera_de_ruta)->toBeFalse()
        ->and($creado->motivo_fuera_ruta)->toBeNull();
});

test('administración arma una ruta con un departamento completo y sus vendedores', function (): void {
    $otro = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otro->assignRole(Rol::Vendedor->value);

    $this->actingAs($this->admin);

    Livewire::test(ManageRutas::class)
        ->callAction('create', data: [
            'bodega_id'            => $this->bodega->id,
            'nombre'               => 'Ruta Ocotepeque',
            'agregar_departamento' => $this->ocotepeque->id,
            'vendedores'           => [$otro->id],
        ])
        ->assertHasNoActionErrors();

    $ruta = Ruta::query()->where('nombre', 'RUTA OCOTEPEQUE')->firstOrFail();

    expect($ruta->municipios()->count())->toBe($this->ocotepeque->municipios()->count())
        ->and($ruta->vendedores()->pluck('users.id')->all())->toBe([$otro->id])
        ->and($otro->municipiosDeRuta())->toContain($this->sinuapa->id);
});

test('bodega solo ve las rutas de su bodega', function (): void {
    $otraRuta = Ruta::factory()->cubre($this->sinuapa)->create();

    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);

    $this->actingAs($encargado);

    Livewire::test(ManageRutas::class)
        ->assertCanSeeTableRecords([$this->ruta])
        ->assertCanNotSeeTableRecords([$otraRuta])
        ->assertActionHidden('create');
});

test('al vendedor se le asignan rutas desde Usuarios y solo a los vendedores les aparece', function (): void {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Utils::getSuperAdminName());
    $this->actingAs($superAdmin);

    $nueva = Ruta::factory()->cubre($this->dolores)->create(['bodega_id' => $this->bodega->id]);

    Livewire::test(EditUser::class, ['record' => $this->vendedor->getRouteKey()])
        ->assertSee('Rutas que trabaja el vendedor')
        ->fillForm(['rutas' => [(string) $this->ruta->id, (string) $nueva->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->vendedor->municipiosDeRuta())->toEqualCanonicalizing([$this->santaRosa->id, $this->dolores->id]);

    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);

    Livewire::test(EditUser::class, ['record' => $encargado->getRouteKey()])
        ->assertDontSee('Rutas que trabaja el vendedor');
});

test('la bodega guarda su departamento y municipio', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ManageBodegas::class)
        ->callAction('create', data: [
            'codigo'          => 'LUC',
            'nombre'          => 'Lucerna',
            'departamento_id' => $this->sinuapa->departamento_id,
            'municipio_id'    => $this->sinuapa->id,
        ])
        ->assertHasNoActionErrors();

    expect(Bodega::query()->where('codigo', 'LUC')->firstOrFail()->municipio_id)->toBe($this->sinuapa->id);
});
