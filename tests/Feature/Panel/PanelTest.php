<?php

declare(strict_types=1);

use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Enums\EstadoPedido;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Filament\Resources\Bodegas\Pages\ManageBodegas;
use App\Filament\Resources\Cais\Pages\ManageCais;
use App\Filament\Resources\CambiosPrecio\Pages\ListCambiosPrecio;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Resources\Facturas\Pages\ListFacturas;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Filament\Resources\Productos\Pages\CreateProducto;
use App\Filament\Resources\Productos\Pages\ListProductos;
use App\Filament\Resources\Productos\Pages\ViewProducto;
use App\Filament\Resources\Proveedores\Pages\EditProveedor;
use App\Filament\Resources\Proveedores\Pages\ListProveedores;
use App\Filament\Resources\Rutas\Pages\ManageRutas;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Municipio;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Ruta;
use App\Models\User;
use Database\Seeders\RolesSistemaSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Pruebas de humo del panel: que cada pantalla abra y que los dos flujos
 * principales (actualizar precio y preventa → despacho) funcionen de punta a punta.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id, 'codigo' => 'HO77']);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    $this->producto = Producto::factory()->create();
});

test('las pantallas de administración abren', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ListProductos::class)->assertOk()->assertCanSeeTableRecords([$this->producto]);
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])->assertOk();
    Livewire::test(CreateProducto::class)->assertOk();
    Livewire::test(ListCambiosPrecio::class)->assertOk();
    Livewire::test(ListProveedores::class)->assertOk();
    Livewire::test(EditProveedor::class, ['record' => $this->producto->proveedor_id])->assertOk();
    Livewire::test(ManageClientes::class)->assertOk();
    Livewire::test(ManageBodegas::class)->assertOk();
    Livewire::test(ManageRutas::class)->assertOk();
    Livewire::test(ManageCais::class)->assertOk();
    Livewire::test(ListFacturas::class)->assertOk();
    Livewire::test(ListPedidos::class)->assertOk();
});

// "Actualizar precio": las cuentas pasan en el navegador mientras se
// escribe; al servidor llega el estado de PreciosPresentaciones con las
// filas de la más grande a la base (0 = CJ12, 1 = UN), precios sin ISV y
// el % (14 = 14 %). El factory deja costo 50 por unidad (16 % de la
// categoría), la UN a 59.5238 y la CJ12 a 714.2856. El costo no se
// escribe: es el de las compras.

test('actualizar precio: abre con el costo de las compras y una fila por presentación', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->assertSchemaStateSet([
            'precios.corregir'            => false,
            'precios.costo_base'          => 50.0,
            'precios.filas.0.id'          => presentacion($this->producto, 12)->id,
            'precios.filas.0.modo'        => 'calculado',
            'precios.filas.0.margen'      => 16.0,
            'precios.filas.0.precio_base' => 714.2856,
            'precios.filas.1.id'          => presentacion($this->producto)->id,
            'precios.filas.1.precio_base' => 59.5238,
        ])
        ->assertMountedActionModalSee('Costo promedio por UN')
        ->assertMountedActionModalSee('Corregir costo');
});

test('actualizar precio: un precio escrito queda fijo y se guarda sin ISV', function (): void {
    $this->actingAs($this->encargado);

    // La UN a L 80.50 con ISV = 70 sin ISV (deja 28.57 %). La CJ12 sigue con su %.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm([
            'precios.filas.0.modo'        => 'calculado',
            'precios.filas.1.modo'        => 'fijo',
            'precios.filas.1.precio_base' => 70.0,
            'precios.filas.1.margen'      => 28.57,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $un = presentacion($this->producto);
    $cambio = $this->producto->cambiosPrecio()->where('presentacion_id', $un->id)->sole();

    expect($this->producto->fresh()?->costo_unidad)->toBe(50.0)
        ->and($un->precio)->toBe(70.0)
        ->and($un->precio_fijo)->toBeTrue()
        ->and(round($un->precioConIsv(), 2))->toBe(80.5)
        ->and(presentacion($this->producto, 12)->precio)->toBe(714.2856)
        ->and(presentacion($this->producto, 12)->precio_fijo)->toBeFalse()
        ->and($cambio->motivo)->toBe(MotivoCambioPrecio::Manual)
        ->and($cambio->margen)->toBe(0.2857)
        ->and($cambio->user_id)->toBe($this->encargado->id)
        ->and($cambio->bodega_id)->toBe($this->bodega->id);
});

test('cada presentación con su %: la caja deja menos que la unidad y su precio sigue al costo de cada compra', function (): void {
    $this->actingAs($this->encargado);

    // La CJ12 al 10 %: 600 ÷ 0.90 = 666.6667 sin ISV. La UN sigue con el 16 %.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm([
            'precios.filas.0.margen'      => 10,
            'precios.filas.0.precio_base' => 666.6667,
            'precios.filas.1.modo'        => 'calculado',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(presentacion($this->producto, 12)->precio)->toBe(666.6667)
        ->and(presentacion($this->producto, 12)->margen)->toBe(0.1)
        ->and(presentacion($this->producto)->precio)->toBe(59.5238)
        ->and(presentacion($this->producto)->margen)->toBeNull();

    // Compran barato: una CJ12 a L 621 con ISV = 45 la unidad. Bajan los dos y cada uno deja su %.
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->producto->proveedor, [
        ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto, 12)->id, 'cantidad' => 1, 'costo' => 621.0],
    ], $this->encargado);

    expect($this->producto->fresh()?->costo_unidad)->toBe(45.0)
        ->and(presentacion($this->producto, 12)->precio)->toBe(600.0)
        ->and(presentacion($this->producto)->precio)->toBe(53.5714);
});

test('corregir el costo: se guarda el corregido y los de "Con %" salen de él', function (): void {
    $this->actingAs($this->encargado);

    // CJ12 a L 600 con ISV → 43.478261 la unidad sin ISV.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['precios.corregir' => true, 'precios.costo_base' => 43.478261])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->producto->fresh()?->costo_unidad)->toBe(43.4783)
        ->and(presentacion($this->producto)->precio)->toBe(51.7599)
        ->and(presentacion($this->producto, 12)->precio)->toBe(621.1186)
        ->and($this->producto->cambiosPrecio()->first()?->motivo)->toBe(MotivoCambioPrecio::Manual);
});

test('sin "Corregir costo", el costo que llegue no se toca', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['precios.costo_base' => 10])
        ->callMountedAction()
        ->assertNotified('Se guardó sin cambios de precio');

    expect($this->producto->fresh()?->costo_unidad)->toBe(50.0);
});

test('abrir y guardar sin cambiar nada no deja historial', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Se guardó sin cambios de precio');

    expect(presentacion($this->producto)->precio)->toBe(59.5238)
        ->and(presentacion($this->producto, 12)->precio)->toBe(714.2856)
        ->and($this->producto->fresh()?->costo_unidad)->toBe(50.0)
        ->and($this->producto->cambiosPrecio()->count())->toBe(0);
});

test('un % fuera de 0 a 99.99 o un fijo sin precio no se guardan y el modal sigue abierto', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['precios.filas.0.margen' => 100, 'precios.filas.1.modo' => 'calculado'])
        ->callMountedAction()
        ->assertNotified('La utilidad de CJ12 debe ser de 0 % a 99.99 %.')
        ->assertActionMounted('actualizarPrecio')
        ->fillForm(['precios.filas.0.margen' => 16, 'precios.filas.1.modo' => 'fijo', 'precios.filas.1.precio_base' => null])
        ->callMountedAction()
        ->assertNotified('Escriba el precio de UN.');

    expect($this->producto->cambiosPrecio()->count())->toBe(0)
        ->and(presentacion($this->producto)->precio)->toBe(59.5238);
});

test('el vendedor no ve costos ni puede cambiar precios', function (): void {
    $this->actingAs($this->vendedor);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('actualizarPrecio');
});

test('el vendedor levanta la preventa y bodega la despacha', function (): void {
    $municipio = Municipio::factory()->create();
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id, 'municipio_id' => $municipio->id]);
    Ruta::factory()->cubre($municipio)->para($this->vendedor)->create(['bodega_id' => $this->bodega->id]);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreatePedido::class)
        ->fillForm([
            'cliente_id' => $cliente->id,
            'fecha'      => now()->toDateString(),
            'items'      => [
                ['producto_id' => $this->producto->id, 'presentacion_id' => presentacion($this->producto)->id, 'cantidad' => 5],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    $pedido = Pedido::query()->latest('id')->firstOrFail();

    // 5 × 59.5238 = 297.62 + 15% ISV (44.64) = 342.26
    expect($pedido->vendedor_id)->toBe($this->vendedor->id)
        ->and($pedido->bodega_id)->toBe($this->bodega->id)
        ->and($pedido->subtotal)->toBe(297.62)
        ->and($pedido->total)->toBe(342.26);

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionHidden('validarDespacho');

    $this->actingAs($this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertActionVisible('validarDespacho')
        ->callAction('validarDespacho')
        ->assertHasNoActionErrors();

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($pedido->fresh()?->validado_por)->toBe($this->encargado->id);

    facturacionLista();

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->callAction('imprimir')
        ->assertDispatched(ImprimirPedidoAction::EVENTO);

    $this->get(route('pedidos.imprimir', $pedido))
        ->assertOk()
        ->assertSee((string) $pedido->numero);

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Impreso)
        ->and($pedido->fresh()?->impresiones)->toBe(1)
        ->and($pedido->fresh()?->impreso_por)->toBe($this->encargado->id);
});

test('un vendedor no puede imprimir el pedido de otro', function (): void {
    $otro = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $otro->assignRole(Rol::Vendedor->value);

    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);

    $this->actingAs($otro)->get(route('pedidos.imprimir', $pedido))->assertForbidden();
});

test('un usuario desactivado ya no abre las hojas aunque tenga la sesión abierta', function (): void {
    $pedido = Pedido::query()->create([
        'bodega_id'   => $this->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $this->bodega->id])->id,
        'vendedor_id' => $this->vendedor->id,
        'fecha'       => now(),
    ]);

    $this->actingAs($this->encargado)->get(route('pedidos.imprimir', $pedido))->assertOk();

    $this->encargado->update(['is_active' => false]);

    $this->actingAs($this->encargado->fresh())->get(route('pedidos.imprimir', $pedido))->assertForbidden();
});

test('el personal entra con su código de usuario', function (): void {
    expect(auth()->attempt(['codigo' => 'HO77', 'password' => 'password']))->toBeTrue();
});

test('precio fijo: las compras no lo mueven; al abrir se ve lo que deja y con "Con %" vuelve a salir del costo', function (): void {
    $this->actingAs($this->encargado);

    // La UN fija a L 65 con ISV = 56.5217 sin ISV.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['precios.filas.0.modo' => 'calculado', 'precios.filas.1.modo' => 'fijo', 'precios.filas.1.precio_base' => 56.521739, 'precios.filas.1.margen' => 11.54])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(presentacion($this->producto)->precio_fijo)->toBeTrue()
        ->and(presentacion($this->producto)->precio)->toBe(56.5217);

    // Sube el costo a 60 (una compra): la UN fija se queda; la CJ12 sube con su %.
    app(ActualizarPrecioProducto::class)->ejecutar($this->producto->fresh() ?? $this->producto, 60.0, MotivoCambioPrecio::Compra);

    expect(presentacion($this->producto)->precio)->toBe(56.5217)
        ->and(presentacion($this->producto, 12)->precio)->toBe(857.1429);

    // Al abrir: la UN fija deja −6.15 %. Con "Con %" (16 %) vuelve a salir del costo: 71.4286.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->assertSchemaStateSet(['precios.filas.1.modo' => 'fijo', 'precios.filas.1.margen' => -6.15, 'precios.costo_base' => 60.0])
        ->fillForm(['precios.filas.0.modo' => 'calculado', 'precios.filas.1.modo' => 'calculado', 'precios.filas.1.margen' => 16])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(presentacion($this->producto)->precio_fijo)->toBeFalse()
        ->and($this->producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and(presentacion($this->producto)->precio)->toBe(71.4286)
        ->and(presentacion($this->producto, 12)->precio)->toBe(857.1429);
});
