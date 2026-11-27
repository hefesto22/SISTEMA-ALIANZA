<?php

declare(strict_types=1);

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Cargas\FacturasParaCargar;
use App\Domain\Comisiones\ComisionesDelPeriodo;
use App\Domain\Comisiones\PeriodoComision;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\RegistrarCompra;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\ValidarDespacho;
use App\Domain\Pedidos\VentaEnBodega;
use App\Domain\Reportes\Periodo;
use App\Domain\Reportes\VentasDelPeriodo;
use App\Enums\EstadoFactura;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Factura;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

/**
 * Venta en bodega (mayoreo): el usuario de bodega le vende al cliente que
 * llega y le puede dar un % a todo el pedido, hasta su tope (Usuarios), en
 * las líneas sin promoción y nunca por debajo del costo. Queda validada al
 * guardar; si se lo lleva no va en camión. No cuenta para comisiones.
 *
 * El jabón cuesta L 8 la UN y L 96 la CJ12 y se vende a L 10 y L 120 (sin
 * ISV). Hay 12 UN y 3 CJ12.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);
    $this->encargado = User::factory()->create(['bodega_id' => $this->bodega->id, 'descuento_maximo' => 10]);
    $this->encargado->assignRole(Rol::Bodega->value);
    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id, 'descuento_maximo' => 10]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->jabon = Producto::factory()->precio(8.0, 10.0)->create(['margen' => 0.20]);
    $this->un = presentacion($this->jabon);
    $this->cj12 = presentacion($this->jabon, 12);
    app(RegistrarCompra::class)->ejecutar($this->bodega, $this->jabon->proveedor, [
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 12, 'costo' => 9.2],
        ['producto_id' => $this->jabon->id, 'presentacion_id' => $this->cj12->id, 'cantidad' => 3, 'costo' => 110.4],
    ], $this->admin);

    facturacionLista();
    Configuracion::actual()->update(['notas_credito' => false]);
    $this->cliente = Cliente::factory()->create(['bodega_id' => $this->bodega->id]);
});

/**
 * Un pedido del vendedor $vendedor con el % pedido, revisado como en la pantalla.
 *
 * @param list<array{0: int, 1: int}> $lineas [presentacion_id, cantidad]
 */
function pedidoEnBodega(object $prueba, User $vendedor, array $lineas, float $porcentaje, bool $seLoLleva = true): Pedido
{
    $datos = VentaEnBodega::datos(['vendedor_id' => $vendedor->id, 'descuento_mayoreo_porcentaje' => $porcentaje, 'se_lo_lleva' => $seLoLleva]);

    $pedido = Pedido::query()->create([
        'bodega_id'                    => $prueba->bodega->id,
        'cliente_id'                   => $prueba->cliente->id,
        'vendedor_id'                  => $vendedor->id,
        'fecha'                        => now(),
        'en_bodega'                    => $datos['en_bodega'],
        'se_lo_lleva'                  => $datos['se_lo_lleva'],
        'descuento_mayoreo_porcentaje' => $datos['descuento_mayoreo_porcentaje'],
    ]);

    foreach ($lineas as [$presentacion, $cantidad]) {
        $pedido->items()->create(['producto_id' => $prueba->jabon->id, 'presentacion_id' => $presentacion, 'cantidad' => $cantidad]);
    }

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

/**
 * @return list<float>
 */
function descuentosDeBodega(Pedido $pedido): array
{
    return array_values($pedido->items()->orderBy('id')->get()->map(fn (PedidoItem $item): float => $item->descuento_mayoreo)->all());
}

test('el % va a todo el pedido hasta el tope del usuario y nunca por debajo del costo; el vendedor de ruta no da descuento', function (): void {
    expect([$this->encargado->vendeEnBodega(), $this->vendedor->vendeEnBodega()])->toBe([true, false])
        ->and(VentaEnBodega::datos(['vendedor_id' => $this->encargado->id, 'descuento_mayoreo_porcentaje' => 25, 'se_lo_lleva' => '1']))
        ->toMatchArray(['en_bodega' => true, 'descuento_mayoreo_porcentaje' => 10.0, 'se_lo_lleva' => true])
        ->and(VentaEnBodega::datos(['vendedor_id' => $this->vendedor->id, 'descuento_mayoreo_porcentaje' => 5, 'se_lo_lleva' => true]))
        ->toMatchArray(['en_bodega' => false, 'descuento_mayoreo_porcentaje' => 0.0, 'se_lo_lleva' => false]);

    // 5 UN a L 10 = 50 → 5; 1 CJ12 a L 120 → 12.
    $pedido = pedidoEnBodega($this, $this->encargado, [[$this->un->id, 5], [$this->cj12->id, 1]], 10);

    expect(descuentosDeBodega($pedido))->toBe([5.0, 12.0])
        ->and([$pedido->subtotal, $pedido->descuento, $pedido->descuento_mayoreo, $pedido->descuentoPromociones()])->toBe([170.0, 17.0, 17.0, 0.0]);

    // Al 30 % quedaría bajo el costo (L 8 y L 96): se rebaja solo hasta el costo.
    $this->encargado->update(['descuento_maximo' => 30]);
    $pedido = pedidoEnBodega($this, $this->encargado->fresh(), [[$this->un->id, 5], [$this->cj12->id, 1]], 30);

    expect(descuentosDeBodega($pedido))->toBe([10.0, 24.0])
        ->and($pedido->descuento_mayoreo)->toBe(34.0);

    // El vendedor de ruta, aunque tenga tope: sin descuento.
    expect(pedidoEnBodega($this, $this->vendedor, [[$this->un->id, 5]], 10)->descuento_mayoreo)->toBe(0.0);
});

test('la promoción queda sola: a esa línea no se le da descuento, a las demás sí', function (): void {
    // Compre 1 UN y lleve otra a mitad de precio.
    Promocion::factory()->para($this->jabon, $this->jabon, porcentaje: 50)->create();

    $pedido = pedidoEnBodega($this, $this->encargado, [[$this->un->id, 2], [$this->cj12->id, 1]], 10);
    $un = $pedido->items()->where('presentacion_id', $this->un->id)->sole();

    expect($un->descuento)->toBeGreaterThan(0.0)
        ->and($un->descuento_mayoreo)->toBe(0.0)
        ->and($pedido->descuento_mayoreo)->toBe(12.0)
        ->and($pedido->descuentoPromociones())->toBe($un->descuento);
});

test('bodega la vende desde Nueva preventa: queda validada al guardar, si se lo lleva no va en camión y no cuenta en comisiones', function (): void {
    $this->actingAs($this->encargado);
    $undoRepeaterFake = Repeater::fake();

    $datos = [
        'cliente_id'                   => $this->cliente->id,
        'vendedor_id'                  => $this->encargado->id,
        'fecha'                        => now()->toDateString(),
        'descuento_mayoreo_porcentaje' => 15,
        'se_lo_lleva'                  => true,
        'items'                        => [['producto_id' => $this->jabon->id, 'presentacion_id' => $this->un->id, 'cantidad' => 5]],
    ];

    // Más que su tope no se puede.
    Livewire::test(CreatePedido::class)
        ->fillForm($datos)
        ->assertSee('Venta en bodega')
        ->call('create')
        ->assertHasFormErrors(['descuento_mayoreo_porcentaje']);

    Livewire::test(CreatePedido::class)
        ->fillForm([...$datos, 'descuento_mayoreo_porcentaje' => 10])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $undoRepeaterFake();
    $pedido = Pedido::query()->sole();

    expect([$pedido->estado, $pedido->en_bodega, $pedido->se_lo_lleva, $pedido->vendedor_id, $pedido->descuento_mayoreo])
        ->toBe([EstadoPedido::PorImprimir, true, true, $this->encargado->id, 5.0])
        ->and(Existencia::de($this->bodega->id, $this->un->id))->toBe(7);

    imprimir($pedido, $this->encargado);
    $factura = Factura::query()->where('pedido_id', $pedido->id)->where('estado', EstadoFactura::Vigente)->sole();

    // No va en camión.
    expect(FacturasParaCargar::de($this->bodega->id))->toBeEmpty()
        ->and(fn () => app(ArmarCarga::class)->ejecutar(null, [
            'bodega_id'     => $this->bodega->id,
            'fecha'         => today()->toDateString(),
            'repartidor_id' => $this->encargado->id,
            'facturas'      => [$factura->id],
        ], $this->encargado))->toThrow(ReglaNegocioException::class, 'el cliente se llevó');

    // Una del vendedor de ruta, para comparar: esa sí cuenta en comisiones.
    $ruta = pedidoEnBodega($this, $this->vendedor, [[$this->un->id, 1]], 0);
    app(ValidarDespacho::class)->ejecutar($ruta, [], $this->encargado);
    imprimir($ruta->fresh(), $this->encargado);

    expect(array_column((new ComisionesDelPeriodo(PeriodoComision::actual()))->detalle()['ventas'], 'vendedor_id'))->toBe([$this->vendedor->id]);

    // En el reporte de ventas, las promociones y los descuentos van aparte.
    $totales = (new VentasDelPeriodo(Periodo::de('hoy'), null))->totales();

    expect([$totales->promociones, $totales->descuentos, $totales->venta])->toBe([0.0, 5.0, 55.0]);
});

test('va en camión: queda en Impreso para cargarlo; sueltas que no alcanzan abren una caja solas', function (): void {
    // 15 UN: hay 12 sueltas, se abre una CJ12.
    $pedido = pedidoEnBodega($this, $this->encargado, [[$this->un->id, 15]], 0, seLoLleva: false);
    app(VentaEnBodega::class)->validar($pedido, $this->encargado);
    imprimir($pedido->fresh(), $this->encargado);

    expect([Existencia::de($this->bodega->id, $this->un->id), Existencia::de($this->bodega->id, $this->cj12->id)])->toBe([9, 2])
        ->and(FacturasParaCargar::de($this->bodega->id)->pluck('pedido_id')->all())->toBe([$pedido->id]);
});

test('el tope se pone en Usuarios, solo a los usuarios de bodega', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(EditUser::class, ['record' => $this->encargado->getRouteKey()])
        ->assertSee('Venta en bodega (mayoreo)')
        ->fillForm(['descuento_maximo' => 12.5])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->encargado->fresh()?->descuento_maximo)->toBe(12.5);

    Livewire::test(EditUser::class, ['record' => $this->vendedor->getRouteKey()])
        ->assertDontSee('Venta en bodega (mayoreo)');
});
