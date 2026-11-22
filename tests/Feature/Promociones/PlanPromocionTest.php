<?php

declare(strict_types=1);

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Promociones\AvancePlan;
use App\Domain\Promociones\GuardarPlanPromocion;
use App\Domain\Promociones\UsoDePromociones;
use App\Enums\EstadoPedido;
use App\Enums\Rol;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Resources\PlanesPromocion\Pages\CreatePlanPromocion;
use App\Filament\Resources\PlanesPromocion\Pages\ListPlanesPromocion;
use App\Filament\Resources\PlanesPromocion\Pages\ViewPlanPromocion;
use App\Filament\Resources\PlanesPromocion\PlanPromocionResource;
use App\Filament\Resources\Promociones\Pages\ManagePromociones;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\PlanPromocion;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\Proveedor;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');

    $this->bodega = Bodega::factory()->create(['codigo' => 'COP']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Rol::Administrador->value);

    $this->vendedor = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $this->vendedor->assignRole(Rol::Vendedor->value);

    $this->proveedor = Proveedor::factory()->create(['nombre' => 'DEL MONTE']);

    // Dos sabores de la misma línea: UN a L 9.50 (costo 7.90) y CJ de 24.
    $this->manzana = Producto::factory()->precio(7.9, 9.5)->conCaja(24)->create(['descripcion' => 'NECTAR MANZANA TETRA 200', 'proveedor_id' => $this->proveedor->id]);
    $this->pera = Producto::factory()->precio(7.9, 9.5)->conCaja(24)->create(['descripcion' => 'NECTAR PERA TETRA 200', 'proveedor_id' => $this->proveedor->id]);
});

/**
 * Plan con una línea "24+1" de manzana y pera (se regala manzana) y el tope dado.
 */
function planNectar(object $prueba, int $tope, ?User $usuario = null): PlanPromocion
{
    return app(GuardarPlanPromocion::class)->ejecutar(null, [
        'proveedor_id'  => $prueba->proveedor->id,
        'nombre'        => 'Plan OPL noviembre 2026',
        'vigente_desde' => today()->startOfMonth()->toDateString(),
        'vigente_hasta' => today()->endOfMonth()->toDateString(),
        'bodegas'       => [],
        'lineas'        => [
            ['id' => null, 'productos' => [$prueba->manzana->id, $prueba->pera->id], 'compra' => 24, 'regala' => 1, 'regalo_id' => $prueba->manzana->id, 'tope' => $tope, 'porcentaje' => 4.17],
        ],
    ], $usuario ?? $prueba->admin);
}

/**
 * Preventa con esas líneas [presentación, cuántas], recalculada como al guardarla.
 *
 * @param list<array{0: Presentacion, 1: int}> $lineas
 */
function preventaNectar(object $prueba, array $lineas): Pedido
{
    $pedido = Pedido::query()->create([
        'bodega_id'   => $prueba->bodega->id,
        'cliente_id'  => Cliente::factory()->create(['bodega_id' => $prueba->bodega->id])->id,
        'vendedor_id' => $prueba->vendedor->id,
        'fecha'       => now(),
    ]);

    foreach ($lineas as [$presentacion, $cantidad]) {
        $pedido->items()->create(['producto_id' => $presentacion->producto_id, 'presentacion_id' => $presentacion->id, 'cantidad' => $cantidad]);
    }

    return app(RecalcularPedido::class)->ejecutar($pedido);
}

/**
 * Unidades de regalo del pedido.
 */
function regaloDe(Pedido $pedido): int
{
    return (int) $pedido->items()->where('bonificacion', true)->sum('cantidad');
}

test('cada línea del plan queda como promoción gratis que cuenta unidades, con su tope', function (): void {
    $plan = planNectar($this, 1000);
    $linea = $plan->promociones()->with(Promocion::PARA_CALCULAR)->sole();

    expect($plan->nombre)->toBe('PLAN OPL NOVIEMBRE 2026')
        ->and($linea->plan_id)->toBe($plan->id)
        ->and($linea->mecanica())->toBe('24+1')
        ->and($linea->tope_unidades)->toBe(1000)
        ->and($linea->esBonificacion())->toBeTrue()
        ->and($linea->nombre)->toBe('24+1 NECTAR MANZANA TETRA 200 Y 1 MÁS')
        ->and($linea->condiciones->pluck('producto_id')->all())->toBe([$this->manzana->id, $this->pera->id])
        ->and($linea->condiciones->pluck('presentacion_id')->all())->toBe([presentacion($this->manzana)->id, presentacion($this->pera)->id])
        ->and($linea->resumen())->toBe('Por cada 24 unidades de NECTAR MANZANA TETRA 200 o NECTAR PERA TETRA 200 (se pueden mezclar) lleva 1 unidad de NECTAR MANZANA TETRA 200 gratis')
        ->and(GuardarPlanPromocion::porcentaje(24, 1, presentacion($this->manzana), presentacion($this->manzana)))->toBe(4.17);
});

test('24+1 se cumple mezclando sabores, sueltas o en caja', function (): void {
    planNectar($this, 1000);

    // 12 UN de manzana + 1 CJ24 de pera = 36 unidades → 1 de regalo.
    expect(regaloDe(preventaNectar($this, [[presentacion($this->manzana), 12], [caja($this->pera), 1]])))->toBe(1)
        // 2 CJ24 = 48 → 2.
        ->and(regaloDe(preventaNectar($this, [[caja($this->manzana), 2]])))->toBe(2)
        // 23 no alcanzan.
        ->and(regaloDe(preventaNectar($this, [[presentacion($this->pera), 23]])))->toBe(0);
});

test('no se regala más de lo autorizado: se aparta desde la preventa y al anular regresa', function (): void {
    $linea = planNectar($this, 3)->promociones()->sole();

    $a = preventaNectar($this, [[presentacion($this->manzana), 24]]);
    $b = preventaNectar($this, [[caja($this->manzana), 2]]);

    expect(regaloDe($a))->toBe(1)
        ->and(regaloDe($b))->toBe(2)
        ->and(UsoDePromociones::disponibles([$linea->fresh() ?? $linea]))->toBe([$linea->id => 0]);

    // Agotada: le tocaba 1 y ya no queda.
    $c = preventaNectar($this, [[presentacion($this->pera), 24]]);
    expect(regaloDe($c))->toBe(0);

    // Recalcular B no le quita lo suyo.
    expect(regaloDe(app(RecalcularPedido::class)->ejecutar($b->fresh() ?? $b)))->toBe(2);

    // Al anular A, su regalo regresa y C lo toma.
    $a->forceFill(['estado' => EstadoPedido::Anulado])->save();

    expect(regaloDe(app(RecalcularPedido::class)->ejecutar($c->fresh() ?? $c)))->toBe(1);
});

test('si quedan menos de las que le tocan, se le da lo que queda y se avisa', function (): void {
    planNectar($this, 2);

    $resumen = PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 3]], $this->bodega->id);

    expect($resumen['promociones'])->toContain('le tocaban 3, solo quedaban 2 de las autorizadas')
        ->and(regaloDe(preventaNectar($this, [[caja($this->manzana), 3]])))->toBe(2);

    $agotada = PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->pera->id, 'presentacion_id' => caja($this->pera)->id, 'cantidad' => 1]], $this->bodega->id);

    expect($agotada['promociones'])->toContain('agotada: le tocaban 1 unidad de NECTAR MANZANA TETRA 200');
});

test('el avance separa lo entregado (impreso) de lo comprometido y lo valora al costo', function (): void {
    $plan = planNectar($this, 10);

    $impreso = preventaNectar($this, [[caja($this->manzana), 2]]);
    $impreso->forceFill(['estado' => EstadoPedido::Impreso])->save();
    preventaNectar($this, [[caja($this->pera), 1]]);

    $avance = AvancePlan::de($plan);

    expect($avance['totales']['tope'])->toBe(10)
        ->and($avance['totales']['entregadas'])->toBe(2)
        ->and($avance['totales']['comprometidas'])->toBe(1)
        ->and($avance['totales']['quedan'])->toBe(7)
        ->and($avance['totales']['usado'])->toBe(30.0)
        ->and($avance['totales']['autorizado'])->toBe(79.0)
        ->and($avance['totales']['invertido'])->toBe(15.8)
        ->and($avance['lineas'][0]['porcentaje'])->toBe(4.17)
        ->and($avance['lineas'][0]['excede'])->toBeFalse();
});

test('al cambiar el plan, la línea usada sigue con lo ya regalado y no se puede quitar', function (): void {
    $plan = planNectar($this, 3);
    $linea = $plan->promociones()->sole();
    preventaNectar($this, [[caja($this->manzana), 1]]);

    $datos = [
        'proveedor_id'  => $this->proveedor->id,
        'nombre'        => 'Plan OPL noviembre 2026',
        'vigente_desde' => today()->startOfMonth()->toDateString(),
        'vigente_hasta' => today()->endOfMonth()->toDateString(),
        'bodegas'       => [],
        'lineas'        => [['id' => $linea->id, 'productos' => [$this->manzana->id], 'compra' => 24, 'regala' => 1, 'regalo_id' => $this->manzana->id, 'tope' => 5, 'porcentaje' => null]],
    ];

    $plan = app(GuardarPlanPromocion::class)->ejecutar($plan, $datos, $this->admin);

    expect($plan->promociones()->pluck('id')->all())->toBe([$linea->id])
        ->and(UsoDePromociones::disponibles([$linea->fresh() ?? $linea]))->toBe([$linea->id => 4])
        ->and($plan->seUso())->toBeTrue();

    $otro = Producto::factory()->create(['proveedor_id' => $this->proveedor->id]);
    $datos['lineas'] = [['id' => null, 'productos' => [$otro->id], 'compra' => 12, 'regala' => 1, 'regalo_id' => $otro->id, 'tope' => 5, 'porcentaje' => null]];

    expect(fn () => app(GuardarPlanPromocion::class)->ejecutar($plan, $datos, $this->admin))
        ->toThrow(ReglaNegocioException::class, 'ya se dio en pedidos');
});

test('el encargado arma planes solo de su bodega y el vendedor no los ve', function (): void {
    $encargado = User::factory()->create(['bodega_id' => $this->bodega->id]);
    $encargado->assignRole(Rol::Bodega->value);
    $otra = Bodega::factory()->create(['codigo' => 'OCO']);

    $plan = app(GuardarPlanPromocion::class)->ejecutar(null, [
        'proveedor_id'  => $this->proveedor->id,
        'nombre'        => 'Plan de Copán',
        'vigente_desde' => today()->toDateString(),
        'vigente_hasta' => today()->toDateString(),
        'bodegas'       => [$otra->id],
        'lineas'        => [['id' => null, 'productos' => [$this->pera->id], 'compra' => 24, 'regala' => 1, 'regalo_id' => $this->pera->id, 'tope' => 5]],
    ], $encargado);

    expect($plan->bodegas->pluck('id')->all())->toBe([$this->bodega->id])
        ->and($plan->promociones()->sole()->bodegas->pluck('id')->all())->toBe([$this->bodega->id])
        ->and($encargado->can('update', $plan))->toBeTrue()
        ->and($this->vendedor->can('viewAny', PlanPromocion::class))->toBeFalse()
        ->and($this->admin->can('viewAny', PlanPromocion::class))->toBeTrue();
});

test('administración ve la lista, el formulario y el avance del plan', function (): void {
    $plan = planNectar($this, 1000);
    $this->actingAs($this->admin);

    Livewire::test(ListPlanesPromocion::class)
        ->assertOk()
        ->assertSee('PLAN OPL NOVIEMBRE 2026')
        ->assertSee('DEL MONTE');

    Livewire::test(CreatePlanPromocion::class)
        ->assertOk()
        ->assertSee('Líneas autorizadas');

    Livewire::test(ViewPlanPromocion::class, ['record' => $plan->getRouteKey()])
        ->assertOk()
        ->assertSee('Regalo autorizado')
        ->assertSee('24+1')
        ->assertSee('NECTAR PERA TETRA 200');

    $this->get(PlanPromocionResource::getUrl('index'))->assertOk();
});

test('las promociones del plan no se cambian sueltas: muestran su plan y lo que queda', function (): void {
    $linea = planNectar($this, 5)->promociones()->sole();
    preventaNectar($this, [[caja($this->manzana), 1]]);
    $this->actingAs($this->admin);

    Livewire::test(ManagePromociones::class)
        ->assertOk()
        ->assertSee('PLAN OPL NOVIEMBRE 2026')
        ->assertSee('quedan 4 de 5 UN de regalo')
        ->assertTableActionHidden('edit', $linea)
        ->assertTableActionHidden('delete', $linea);

    expect(PedidoItem::query()->where('promocion_id', $linea->id)->where('bonificacion', true)->sum('cantidad'))->toBe(1);
});

test('en la preventa la línea del plan dice cuánto regalo queda y agrega la caja que completa', function (): void {
    planNectar($this, 3);
    preventaNectar($this, [[caja($this->manzana), 1]]);
    $this->actingAs($this->vendedor);

    /** @var array{promos: list<array<string, mixed>>, productos: array<int, array<string, mixed>>} $hoy */
    $hoy = Livewire::test(CreatePedido::class)
        ->instance()
        ->callSchemaComponentMethod('form.items', 'promocionesDeHoy', []);

    expect($hoy['promos'][0]['tope'])->toBe(3)
        ->and($hoy['promos'][0]['quedan'])->toBe(2)
        ->and($hoy['promos'][0]['sigla'])->toBe('UN')
        ->and($hoy['promos'][0]['compra'])->toBe([$this->manzana->id, $this->pera->id])
        ->and($hoy['promos'][0]['lineas'])->toBe([['producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 1]]);
});
