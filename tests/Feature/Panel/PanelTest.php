<?php

declare(strict_types=1);

use App\Enums\EstadoPedido;
use App\Enums\MotivoCambioPrecio;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Bodegas\Pages\ManageBodegas;
use App\Filament\Resources\CambiosPrecio\Pages\ListCambiosPrecio;
use App\Filament\Resources\Clientes\Pages\ManageClientes;
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
    Livewire::test(ListPedidos::class)->assertOk();
});

test('actualizar precio: se escriben costo y precio con ISV y se guardan sin ISV', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: [
            'costo_por'    => 'unidad',
            'costo'        => 69,
            'precio_venta' => 82.14,
        ])
        ->assertHasNoActionErrors();

    // 69 ÷ 1.15 = 60 de costo; 82.14 ÷ 1.15 = 71.4261 de precio sin ISV (al cliente vuelve a dar 82.14).
    $producto = $this->producto->fresh();
    $cambio = $this->producto->cambiosPrecio()->first();

    expect($producto?->costo_unidad)->toBe(60.0)
        ->and($producto?->precio_unidad)->toBe(71.4261)
        ->and(round((float) $producto?->precioUnidadConIsv(), 2))->toBe(82.14)
        ->and($cambio?->motivo)->toBe(MotivoCambioPrecio::Compra)
        ->and($cambio?->user_id)->toBe($this->encargado->id)
        ->and($cambio?->bodega_id)->toBe($this->bodega->id);
});

test('al cambiar el costo, el precio de venta se recalcula solo con el % de utilidad', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['costo_por' => 'unidad', 'costo' => 69])
        ->assertHasNoActionErrors();

    // Costo 69 con ISV = 60 neto; con 16% → 71.4286 sin ISV = L. 82.14 con ISV.
    $producto = $this->producto->fresh();

    expect($producto?->costo_unidad)->toBe(60.0)
        ->and($producto?->precio_unidad)->toBe(71.4286)
        ->and(round((float) $producto?->precioUnidadConIsv(), 2))->toBe(82.14);
});

test('lo escrito por caja se divide entre la caja al pasar a unidad, sin descuadrarse', function (): void {
    $this->actingAs($this->encargado);

    // Caja de 12: costo L. 600 con ISV → precio sugerido con 16% = L. 714.29 la caja.
    // Al pasar a unidad: L. 50.00 de costo y L. 59.52 de precio (no 600 × 12).
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['costo' => 600])
        ->assertSchemaStateSet(['precio_venta' => 714.29])
        ->fillForm(['costo_por' => 'unidad'])
        ->assertSchemaStateSet(['costo' => 50.0, 'precio_venta' => 59.52])
        ->fillForm(['costo_por' => 'caja'])
        ->assertSchemaStateSet(['costo' => 600.0, 'precio_venta' => 714.29])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $producto = $this->producto->fresh();

    expect($producto?->costo_unidad)->toBe(43.4783)
        ->and($producto?->precio_unidad)->toBe(51.7598);
});

test('si solo cambia el precio de venta, el costo queda igual', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['costo_por' => 'unidad', 'precio_venta' => 70])
        ->assertHasNoActionErrors();

    $producto = $this->producto->fresh();

    expect($producto?->costo_unidad)->toBe(50.0)
        ->and($producto?->precio_unidad)->toBe(60.8696)
        ->and($this->producto->cambiosPrecio()->first()?->motivo)->toBe(MotivoCambioPrecio::Manual);
});

test('pasar de caja a unidad convierte costo y precio y nada cambia', function (): void {
    $this->actingAs($this->encargado);

    // El modal abre por caja de 12 (costo L. 690.00, precio L. 821.43 con ISV); al pasar a unidad queda L. 57.50 y L. 68.45.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['costo_por' => 'unidad'])
        ->assertHasNoActionErrors();

    expect($this->producto->fresh()?->precio_unidad)->toBe(59.5238)
        ->and($this->producto->fresh()?->costo_unidad)->toBe(50.0)
        ->and($this->producto->cambiosPrecio()->count())->toBe(0);
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
                ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 5],
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

test('el personal entra con su código de usuario', function (): void {
    expect(auth()->attempt(['codigo' => 'HO77', 'password' => 'password']))->toBeTrue();
});
