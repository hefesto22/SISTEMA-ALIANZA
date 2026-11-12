<?php

declare(strict_types=1);

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

// "Actualizar precio" con Repeater::fake(): las filas de presentaciones van
// de la más grande a la base, así que 0 = CJ12 y 1 = UN. El factory deja
// costo 50 por unidad, la UN a 59.5238 y la CJ12 a 714.2856 (sin ISV).

test('actualizar precio: se escriben costo y precio con ISV y se guardan sin ISV', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: [
            'costo_por'      => presentacion($this->producto)->id,
            'costo'          => 69,
            'presentaciones' => [['modo' => 'calculado'], ['precio' => 80.50]],
        ])
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    // 69 ÷ 1.15 = 60 de costo. La UN: 80.50 ÷ 1.15 = 70 sin ISV, distinto al calculado → queda fija.
    // La CJ12 sigue calculada: 60 × 12 = 720 con 16 % → 857.1429.
    $un = presentacion($this->producto);
    $cambio = $this->producto->cambiosPrecio()->where('presentacion_id', $un->id)->first();

    expect($this->producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and($un->precio)->toBe(70.0)
        ->and($un->precio_fijo)->toBeTrue()
        ->and(round($un->precioConIsv(), 2))->toBe(80.5)
        ->and(presentacion($this->producto, 12)->precio)->toBe(857.1429)
        ->and(presentacion($this->producto, 12)->precio_fijo)->toBeFalse()
        ->and($cambio?->motivo)->toBe(MotivoCambioPrecio::Compra)
        ->and($cambio?->margen)->toBe(0.1429)
        ->and($cambio?->user_id)->toBe($this->encargado->id)
        ->and($cambio?->bodega_id)->toBe($this->bodega->id);
});

test('al cambiar el costo, el precio de cada presentación se recalcula solo con el % de utilidad', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['costo_por' => presentacion($this->producto)->id, 'costo' => 69])
        ->assertHasNoActionErrors();

    // Costo 69 con ISV = 60 neto; con 16% → UN 71.4286 sin ISV = L. 82.14 con ISV; CJ12 857.1429 = L. 985.71.
    expect($this->producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and(presentacion($this->producto)->precio)->toBe(71.4286)
        ->and(round(presentacion($this->producto)->precioConIsv(), 2))->toBe(82.14)
        ->and(presentacion($this->producto, 12)->precio)->toBe(857.1429)
        ->and(round(presentacion($this->producto, 12)->precioConIsv(), 2))->toBe(985.71);
});

test('el costo escrito por caja se reparte por unidad al cambiar la presentación del costo, sin descuadrarse', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    // El modal abre con el costo por CJ12 (la más grande). L. 600 con ISV la caja → L. 714.29 la caja y L. 59.52 la UN.
    // Al pasar el costo a UN: L. 50.00 (no 600 × 12); al regresar a CJ12, otra vez L. 600.00.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->assertSchemaStateSet(['costo_por' => presentacion($this->producto, 12)->id, 'costo' => 690.0])
        ->fillForm(['costo' => 600])
        ->assertSchemaStateSet(['presentaciones.0.precio' => 714.29, 'presentaciones.1.precio' => 59.52])
        ->fillForm(['costo_por' => presentacion($this->producto)->id])
        ->assertSchemaStateSet(['costo' => 50.0, 'presentaciones.0.precio' => 714.29, 'presentaciones.1.precio' => 59.52])
        ->fillForm(['costo_por' => presentacion($this->producto, 12)->id])
        ->assertSchemaStateSet(['costo' => 600.0])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    expect($this->producto->fresh()?->costo_unidad)->toBe(43.4783)
        ->and(presentacion($this->producto)->precio)->toBe(51.7599)
        ->and(presentacion($this->producto, 12)->precio)->toBe(621.1186);
});

test('si solo cambia el precio de venta, el costo queda igual', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['presentaciones' => [['modo' => 'calculado'], ['precio' => 70]]])
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    $un = presentacion($this->producto);

    expect($this->producto->fresh()?->costo_unidad)->toBe(50.0)
        ->and($un->precio)->toBe(60.8696)
        ->and($un->precio_fijo)->toBeTrue()
        ->and(presentacion($this->producto, 12)->precio)->toBe(714.2856)
        ->and($this->producto->cambiosPrecio()->sole()->motivo)->toBe(MotivoCambioPrecio::Manual);
});

test('cambiar la presentación del costo convierte el monto y nada cambia', function (): void {
    $this->actingAs($this->encargado);

    // El modal abre por CJ12 (costo L. 690.00); al pasar a UN queda L. 57.50: el mismo costo.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->fillForm(['costo_por' => presentacion($this->producto)->id])
        ->assertSchemaStateSet(['costo' => 57.5])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Se guardó sin cambios de precio');

    expect(presentacion($this->producto)->precio)->toBe(59.5238)
        ->and(presentacion($this->producto, 12)->precio)->toBe(714.2856)
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

test('precio de venta: si se escribe otro precio queda fijo; con "Calculado" vuelve a salir del costo con el %', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    // Costo 50 con 16 % → la UN a L 68.45 con ISV; se escribe L 65: queda fija. La CJ12 sigue calculada.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->assertSchemaStateSet(['presentaciones.1.modo' => 'calculado', 'presentaciones.1.precio' => 68.45])
        ->fillForm(['presentaciones' => [['modo' => 'calculado'], ['precio' => 65]]])
        ->assertSchemaStateSet(['presentaciones.1.modo' => 'fijo', 'presentaciones.0.modo' => 'calculado'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(presentacion($this->producto)->precio_fijo)->toBeTrue()
        ->and(presentacion($this->producto)->precio)->toBe(56.5217)
        ->and(presentacion($this->producto, 12)->precio_fijo)->toBeFalse();

    // Fija: al cambiar el costo su precio se queda (la CJ12 sí se recalcula); con "Calculado" se recalcula.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->mountAction('actualizarPrecio')
        ->assertSchemaStateSet(['presentaciones.1.modo' => 'fijo'])
        ->fillForm(['costo_por' => presentacion($this->producto)->id, 'costo' => 69])
        ->assertSchemaStateSet(['presentaciones.1.precio' => 65.0, 'presentaciones.0.precio' => 985.71])
        ->fillForm(['presentaciones' => [['modo' => 'calculado'], ['modo' => 'calculado']]])
        ->assertSchemaStateSet(['presentaciones.1.precio' => 82.14])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $undoRepeaterFake();

    expect(presentacion($this->producto)->precio_fijo)->toBeFalse()
        ->and($this->producto->fresh()?->costo_unidad)->toBe(60.0)
        ->and(presentacion($this->producto)->precio)->toBe(71.4286)
        ->and(presentacion($this->producto, 12)->precio)->toBe(857.1429);
});
