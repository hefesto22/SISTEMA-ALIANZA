<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\AnularPedido;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Enums\EstadoPedido;
use App\Enums\ModoImpresion;
use App\Enums\Rol;
use App\Enums\UnidadVenta;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Pages\EditPedido;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
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
 * A qué bodega le toca cada pedido (la del pedido, no la que tenga hoy el
 * vendedor) y que nadie pise lo que bodega ya validó.
 */
beforeEach(function (): void {
    $this->seed(RolesSistemaSeeder::class);
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->otraBodega = Bodega::factory()->create(['codigo' => 'OCO']);

    $this->vendedor = User::factory()->create(['name' => 'MARIO VENDEDOR', 'bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->encargado->assignRole(Rol::Bodega->value);

    $this->encargadoOtra = User::factory()->create(['bodega_id' => $this->otraBodega->id]);
    $this->encargadoOtra->assignRole(Rol::Bodega->value);

    $this->producto = Producto::factory()->precio(80.0, 100.0)->create(['unidades_por_caja' => 12]);
});

/**
 * Pedido pendiente de 2 unidades (L 230 con ISV).
 */
function pedidoDeAlcance(Bodega $bodega, User $vendedor, Producto $producto): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $bodega->id])->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now(),
    ]);

    $pedido->items()->create(['producto_id' => $producto->id, 'unidad' => UnidadVenta::Unidad, 'cantidad' => 2]);

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

test('si el vendedor se pasa a otra bodega, sus pedidos se quedan con la bodega que los despacha', function (): void {
    $pedido = pedidoDeAlcance($this->bodega, $this->vendedor, $this->producto);

    $this->vendedor->update(['bodega_id' => $this->otraBodega->id]);

    expect(Pedido::query()->visiblePara($this->encargado)->pluck('id')->all())->toBe([$pedido->id])
        ->and(Pedido::query()->visiblePara($this->encargadoOtra)->count())->toBe(0)
        ->and($this->encargado->can('validar', $pedido))->toBeTrue()
        ->and($this->encargadoOtra->can('validar', $pedido))->toBeFalse()
        ->and(Pedido::query()->visiblePara($this->vendedor)->pluck('id')->all())->toBe([$pedido->id]);
});

test('el encargado solo escoge vendedores de su bodega', function (): void {
    $deOtra = User::factory()->create(['bodega_id' => $this->otraBodega->id]);
    $deOtra->assignRole(Rol::Vendedor->value);
    $cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);

    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    $datos = fn (User $vendedor): array => [
        'cliente_id'  => $cliente->id,
        'vendedor_id' => $vendedor->id,
        'fecha'       => now()->toDateString(),
        'items'       => [
            ['producto_id' => $this->producto->id, 'unidad' => UnidadVenta::Unidad->value, 'cantidad' => 1],
        ],
    ];

    Livewire::test(CreatePedido::class)
        ->fillForm($datos($deOtra))
        ->call('create')
        ->assertHasFormErrors(['vendedor_id']);

    expect(Pedido::query()->count())->toBe(0);

    Livewire::test(CreatePedido::class)
        ->fillForm($datos($this->vendedor))
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    expect(Pedido::query()->sole()->bodega_id)->toBe($this->bodega->id);
});

test('el pedido de un vendedor dado de baja se sigue viendo e imprimiendo con su nombre', function (): void {
    facturacionLista();

    $pedido = app(ValidarDespacho::class)->ejecutar(pedidoDeAlcance($this->bodega, $this->vendedor, $this->producto), [], $this->encargado);

    $this->vendedor->delete();

    $resultado = imprimir($pedido, $this->encargado, ModoImpresion::Originales);

    expect($resultado->facturas)->toHaveCount(1)
        ->and($pedido->fresh()?->vendedor?->name)->toBe('MARIO VENDEDOR');

    $this->actingAs($this->encargado);

    Livewire::test(ViewPedido::class, ['record' => $pedido->getRouteKey()])
        ->assertOk()
        ->assertSee('MARIO VENDEDOR');

    $this->get($resultado->url())
        ->assertOk()
        ->assertSee('MARIO VENDEDOR');
});

test('lo que bodega ya validó no se guarda desde una edición abierta antes', function (): void {
    $pedido = pedidoDeAlcance($this->bodega, $this->vendedor, $this->producto);

    $this->actingAs($this->vendedor);
    $undoRepeaterFake = Repeater::fake();

    // El vendedor abre la edición; mientras tanto bodega lo valida.
    $edicion = Livewire::test(EditPedido::class, ['record' => $pedido->getRouteKey()]);

    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    // Al guardar, la página revisa de nuevo el permiso (ya no está pendiente):
    // no se guarda nada.
    $edicion
        ->call('save')
        ->assertForbidden();

    $undoRepeaterFake();

    $guardado = $pedido->fresh();

    expect($guardado?->estado)->toBe(EstadoPedido::PorImprimir)
        ->and($guardado?->notas)->toBeNull()
        ->and($guardado?->total)->toBe(230.0);
});

test('no se anula un pedido que bodega validó un instante antes', function (): void {
    $pedido = pedidoDeAlcance($this->bodega, $this->vendedor, $this->producto);

    // $pedido se quedó con el estado de antes (Pendiente), como la pantalla
    // de quien le dio Anular.
    app(ValidarDespacho::class)->ejecutar($pedido, [], $this->encargado);

    expect($pedido->estaPendiente())->toBeTrue()
        ->and(fn () => app(AnularPedido::class)->ejecutar($pedido, 'Cliente cerrado'))
        ->toThrow(ReglaNegocioException::class, 'Solo se pueden anular pedidos pendientes')
        ->and($pedido->fresh()?->estado)->toBe(EstadoPedido::PorImprimir);
});
