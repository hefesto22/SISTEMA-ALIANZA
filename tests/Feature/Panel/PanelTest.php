<?php

declare(strict_types=1);

use App\Enums\EstadoPedido;
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
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\Producto;
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
    Livewire::test(ListPedidos::class)->assertOk();
});

test('actualizar precio desde el panel cambia el precio y deja historial', function (): void {
    $this->actingAs($this->encargado);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: [
            'costo_por'     => 'unidad',
            'costo'         => 60,
            'cantidad'      => 1,
            'descuento'     => 0,
            'precio_manual' => false,
        ])
        ->assertHasNoActionErrors();

    expect($this->producto->fresh()?->precio_unidad)->toBe(71.4286)
        ->and($this->producto->cambiosPrecio()->first()?->user_id)->toBe($this->encargado->id)
        ->and($this->producto->cambiosPrecio()->first()?->bodega_id)->toBe($this->bodega->id);
});

test('pasar de caja a unidad convierte el costo y el precio no salta', function (): void {
    $this->actingAs($this->encargado);

    // El modal abre con el costo por caja (50 × 12 = 600); al cambiar a "por unidad" queda en 50.
    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->callAction('actualizarPrecio', data: ['costo_por' => 'unidad'])
        ->assertHasNoActionErrors();

    expect($this->producto->fresh()?->precio_unidad)->toBe(59.5238)
        ->and($this->producto->cambiosPrecio()->count())->toBe(0);
});

test('el vendedor no ve costos ni puede cambiar precios', function (): void {
    $this->actingAs($this->vendedor);

    Livewire::test(ViewProducto::class, ['record' => $this->producto->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('actualizarPrecio');
});

test('el vendedor levanta la preventa y bodega la despacha', function (): void {
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);

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

    expect($pedido->fresh()?->estado)->toBe(EstadoPedido::Despachado)
        ->and($pedido->fresh()?->validado_por)->toBe($this->encargado->id);

    $this->get(route('pedidos.imprimir', $pedido))
        ->assertOk()
        ->assertSee((string) $pedido->numero);
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
