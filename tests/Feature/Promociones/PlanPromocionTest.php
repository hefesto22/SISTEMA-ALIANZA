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
 * Plan con una línea "24+1" de manzana y pera (cada sabor aparte, regala del mismo) y el tope dado.
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
            ['id' => null, 'productos' => [$prueba->manzana->id, $prueba->pera->id], 'compra' => 24, 'regala' => 1, 'tope' => $tope, 'porcentaje' => 4.17],
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
        ->and($linea->resumen())->toBe('Por cada 24 unidades de un mismo producto (NECTAR MANZANA TETRA 200 o NECTAR PERA TETRA 200) lleva 1 unidad de ese mismo producto gratis; no se mezclan')
        ->and($linea->enPartes())->toBe([
            'mecanica'  => '24+1',
            'titulo'    => '',
            'compra'    => 'Por cada 24 unidades de un mismo producto (no se mezclan)',
            'productos' => ['NECTAR MANZANA TETRA 200', 'NECTAR PERA TETRA 200'],
            'lleva'     => 'Lleva 1 unidad de ese mismo producto gratis',
        ])
        ->and(GuardarPlanPromocion::porcentaje(24, 1, presentacion($this->manzana), presentacion($this->manzana)))->toBe(4.17);
});

test('24+1 cuenta cada sabor aparte (sueltas o en caja) y regala del mismo sabor: no se mezclan', function (): void {
    planNectar($this, 1000);

    // 12 UN de manzana + 12 de pera no completan nada.
    expect(regaloDe(preventaNectar($this, [[presentacion($this->manzana), 12], [presentacion($this->pera), 12]])))->toBe(0)
        // 2 CJ24 de manzana = 48 → 2.
        ->and(regaloDe(preventaNectar($this, [[caja($this->manzana), 2]])))->toBe(2)
        // 23 no alcanzan.
        ->and(regaloDe(preventaNectar($this, [[presentacion($this->pera), 23]])))->toBe(0);

    // 12 de manzana + 1 CJ24 de pera: el regalo es 1 UN de pera.
    $pedido = preventaNectar($this, [[presentacion($this->manzana), 12], [caja($this->pera), 1]]);
    $regalos = $pedido->items()->where('bonificacion', true)->get();

    expect($regalos)->toHaveCount(1)
        ->and($regalos->sole()->producto_id)->toBe($this->pera->id)
        ->and($regalos->sole()->presentacion_id)->toBe(presentacion($this->pera)->id)
        ->and($regalos->sole()->cantidad)->toBe(1);

    // 24 de cada uno: 1 de manzana y 1 de pera.
    $ambos = preventaNectar($this, [[caja($this->manzana), 1], [caja($this->pera), 1]]);

    expect($ambos->items()->where('bonificacion', true)->orderBy('producto_id')->pluck('cantidad', 'producto_id')->all())
        ->toBe([$this->manzana->id => 1, $this->pera->id => 1]);
});

test('el regalo es el que dice la hoja: cada producto cuenta aparte y lo del mismo regalo se junta', function (): void {
    $grande = Producto::factory()->precio(14.0, 17.0)->conCaja(12)->create(['descripcion' => 'NECTAR MANZANA TETRA 330', 'proveedor_id' => $this->proveedor->id]);

    $plan = app(GuardarPlanPromocion::class)->ejecutar(null, [
        'proveedor_id'  => $this->proveedor->id,
        'nombre'        => 'Plan OPL noviembre 2026',
        'vigente_desde' => today()->startOfMonth()->toDateString(),
        'vigente_hasta' => today()->endOfMonth()->toDateString(),
        'bodegas'       => [],
        'lineas'        => [
            // Los sabores de 200 regalan manzana 200; la de 330 también (otra presentación).
            ['id' => null, 'productos' => [$this->manzana->id, $this->pera->id], 'compra' => 24, 'regala' => 1, 'regalo_id' => $this->manzana->id, 'tope' => 5],
            ['id' => null, 'productos' => [$grande->id], 'compra' => 12, 'regala' => 1, 'regalo_id' => $this->manzana->id, 'tope' => 5],
        ],
    ], $this->admin);
    [$sabores, $otra] = $plan->promociones()->with(Promocion::PARA_CALCULAR)->orderBy('id')->get()->all();
    $regalos = fn (Pedido $pedido): array => $pedido->items()->where('bonificacion', true)->orderBy('promocion_id')->get()
        ->map(fn (PedidoItem $item): array => [$item->promocion_id, $item->producto_id, $item->cantidad])->all();

    expect($sabores->regala_el_mismo)->toBeFalse()
        ->and($sabores->resumen())->toBe('Por cada 24 unidades de un mismo producto (NECTAR MANZANA TETRA 200 o NECTAR PERA TETRA 200) lleva 1 unidad de NECTAR MANZANA TETRA 200 gratis; no se mezclan')
        ->and($otra->resumen())->toBe('Por cada 12 unidades de NECTAR MANZANA TETRA 330 lleva 1 unidad de NECTAR MANZANA TETRA 200 gratis')
        ->and($sabores->enPartes()['lleva'])->toBe('Lleva 1 unidad de NECTAR MANZANA TETRA 200 gratis');

    // 24 de pera → 1 de manzana.
    expect($regalos(preventaNectar($this, [[caja($this->pera), 1]])))->toBe([[$sabores->id, $this->manzana->id, 1]])
        // 24 de pera + 24 de manzana → 2 de manzana, en una sola línea.
        ->and($regalos(preventaNectar($this, [[caja($this->pera), 1], [caja($this->manzana), 1]])))->toBe([[$sabores->id, $this->manzana->id, 2]])
        // 12 + 12 no se juntan.
        ->and($regalos(preventaNectar($this, [[presentacion($this->manzana), 12], [presentacion($this->pera), 12]])))->toBe([])
        // 12 de la de 330 → 1 de manzana 200.
        ->and($regalos(preventaNectar($this, [[caja($grande), 1]])))->toBe([[$otra->id, $this->manzana->id, 1]]);

    // El avance la valora al costo del regalo de la hoja.
    expect(AvancePlan::de($plan)['lineas'][1]['regalo'])->toBe('NECTAR MANZANA TETRA 200')
        ->and(AvancePlan::de($plan)['lineas'][1]['costo'])->toBe(7.9);
});

test('una línea que deja mezclar junta las unidades de sus productos y regala el de la hoja', function (): void {
    $plan = app(GuardarPlanPromocion::class)->ejecutar(null, [
        'proveedor_id'  => $this->proveedor->id,
        'nombre'        => 'Plan OPL noviembre 2026',
        'vigente_desde' => today()->startOfMonth()->toDateString(),
        'vigente_hasta' => today()->endOfMonth()->toDateString(),
        'bodegas'       => [],
        'lineas'        => [
            ['id' => null, 'productos' => [$this->manzana->id, $this->pera->id], 'compra' => 24, 'regala' => 1, 'mezclar' => true, 'regalo_id' => $this->pera->id, 'tope' => 10],
        ],
    ], $this->admin);
    $linea = $plan->promociones()->with(Promocion::PARA_CALCULAR)->sole();

    expect($linea->se_mezclan)->toBeTrue()
        ->and($linea->regala_el_mismo)->toBeFalse()
        ->and($linea->resumen())->toBe('Por cada 24 unidades de NECTAR MANZANA TETRA 200 o NECTAR PERA TETRA 200 (se pueden mezclar) lleva 1 unidad de NECTAR PERA TETRA 200 gratis')
        ->and($linea->enPartes()['compra'])->toBe('Por cada 24 unidades, juntando cualquiera de estos (se pueden mezclar)');

    // 12 de manzana + 12 de pera = 24 → 1 de pera; 36 + 12 = 48 → 2.
    $mezcla = preventaNectar($this, [[presentacion($this->manzana), 12], [presentacion($this->pera), 12]]);
    $mas = preventaNectar($this, [[presentacion($this->manzana), 36], [presentacion($this->pera), 12]]);

    expect($mezcla->items()->where('bonificacion', true)->pluck('cantidad', 'producto_id')->all())->toBe([$this->pera->id => 1])
        ->and(regaloDe($mas))->toBe(2)
        ->and(AvancePlan::de($plan)['lineas'][0]['mezclan'])->toBeTrue();
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

    // El tope es de toda la línea: se toma en el orden de la línea (manzana, luego pera).
    $dos = PedidoForm::resumenDe([
        ['id' => null, 'producto_id' => $this->pera->id, 'presentacion_id' => caja($this->pera)->id, 'cantidad' => 2],
        ['id' => null, 'producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 1],
    ], $this->bodega->id);

    expect($dos['promociones'])->toContain('Regalo: 1 unidad de NECTAR MANZANA TETRA 200')
        ->and($dos['promociones'])->toContain('Regalo: 1 unidad de NECTAR PERA TETRA 200')
        ->and($dos['promociones'])->toContain('le tocaban 2, solo quedaban 1 de las autorizadas');

    $resumen = PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 3]], $this->bodega->id);

    expect($resumen['promociones'])->toContain('le tocaban 3, solo quedaban 2 de las autorizadas')
        ->and(regaloDe(preventaNectar($this, [[caja($this->manzana), 3]])))->toBe(2);

    $agotada = PedidoForm::resumenDe([['id' => null, 'producto_id' => $this->pera->id, 'presentacion_id' => caja($this->pera)->id, 'cantidad' => 1]], $this->bodega->id);

    expect($agotada['promociones'])->toContain('agotada: le tocaban 1 unidad de NECTAR PERA TETRA 200');
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
        'lineas'        => [['id' => $linea->id, 'productos' => [$this->manzana->id], 'compra' => 24, 'regala' => 1, 'tope' => 5, 'porcentaje' => null]],
    ];

    $plan = app(GuardarPlanPromocion::class)->ejecutar($plan, $datos, $this->admin);

    expect($plan->promociones()->pluck('id')->all())->toBe([$linea->id])
        ->and(UsoDePromociones::disponibles([$linea->fresh() ?? $linea]))->toBe([$linea->id => 4])
        ->and($plan->seUso())->toBeTrue();

    $otro = Producto::factory()->create(['proveedor_id' => $this->proveedor->id]);
    $datos['lineas'] = [['id' => null, 'productos' => [$otro->id], 'compra' => 12, 'regala' => 1, 'tope' => 5, 'porcentaje' => null]];

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
        'lineas'        => [['id' => null, 'productos' => [$this->pera->id], 'compra' => 24, 'regala' => 1, 'tope' => 5]],
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
        ->and($hoy['promos'][0]['lineas'])->toBe([['producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 1]])
        ->and($hoy['promos'][0]['partes']['mecanica'])->toBe('24+1')
        // Para la ventana de cuántas: cada 24, regala 1, no se mezclan.
        ->and([$hoy['promos'][0]['cada'], $hoy['promos'][0]['regala'], $hoy['promos'][0]['mezcla']])->toBe([24, 1, false])
        // No se mezclan: cada sabor con su botón, que agrega su caja.
        ->and($hoy['promos'][0]['opciones'])->toBe([
            ['producto_id' => $this->manzana->id, 'nombre' => 'NECTAR MANZANA TETRA 200', 'agrega' => '1 '.caja($this->manzana)->sigla(), 'lineas' => [['producto_id' => $this->manzana->id, 'presentacion_id' => caja($this->manzana)->id, 'cantidad' => 1]]],
            ['producto_id' => $this->pera->id, 'nombre' => 'NECTAR PERA TETRA 200', 'agrega' => '1 '.caja($this->pera)->sigla(), 'lineas' => [['producto_id' => $this->pera->id, 'presentacion_id' => caja($this->pera)->id, 'cantidad' => 1]]],
        ]);
});
